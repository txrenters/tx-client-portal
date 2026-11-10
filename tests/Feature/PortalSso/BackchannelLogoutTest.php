<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\PortalSso\PortalSignOuts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PortalSso\InteractsWithPortal;

uses(InteractsWithPortal::class);

beforeEach(function () {
    $this->setUpPortal();
    config(['session.driver' => 'database']);
    Cache::flush();
    $this->fakeJwks();

    $this->maria = $this->staff(['portal_subject' => 'uuid-maria', 'remember_token' => 'old-token']);
    $this->other = $this->staff(['portal_subject' => 'uuid-other']);
    foreach ([[$this->maria, 's1'], [$this->maria, 's2'], [$this->other, 's3']] as [$user, $id]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    $this->postLogout = fn (string $token) => $this->post(route('portal.backchannel-logout'), ['logout_token' => $token]);
});

test('a valid logout token ends only that persons sessions', function () {
    ($this->postLogout)($this->logoutToken())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect(DB::table('sessions')->where('user_id', $this->maria->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->other->id)->count())->toBe(1)
        ->and($this->maria->fresh()->remember_token)->not->toBe('old-token')
        ->and(PortalSignOuts::endedSince('uuid-maria', now()->subMinute()->getTimestamp()))->toBeTrue()
        ->and(PortalSignOuts::endedSince('uuid-other', now()->subMinute()->getTimestamp()))->toBeFalse();
});

test('a visit where the person is signed in as a client ends too', function () {
    config(['session.driver' => 'array']);
    $client = $this->resident();
    ($this->postLogout)($this->logoutToken())->assertOk();

    $this->actingAs($client)
        ->withSession(array_merge($this->portalSession(1, 'uuid-maria'), [ImpersonationController::SESSION_KEY => $this->maria->id]))
        ->get(route('profile.edit'))
        ->assertRedirect(route('portal.redirect'));

    $this->assertGuest();
});

test('an audience array containing the client is accepted', function () {
    ($this->postLogout)($this->logoutToken(['aud' => ['client-123', 'other']]))->assertOk();
});

test('an unknown person is fine and changes nothing', function () {
    ($this->postLogout)($this->logoutToken(['sub' => 'uuid-nobody']))->assertOk();

    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(3);
});

test('forged or wrong tokens are rejected and change nothing', function (string $token) {
    ($this->postLogout)($token)->assertStatus(400);

    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(3)
        ->and(PortalSignOuts::endedSince('uuid-maria', 0))->toBeFalse();
})->with([
    'signed by another key' => [fn () => $this->logoutToken(key: openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))],
    'wrong issuer' => [fn () => $this->logoutToken(['iss' => 'https://evil.example.com'])],
    'wrong audience' => [fn () => $this->logoutToken(['aud' => 'another-app'])],
    'missing events' => [fn () => $this->logoutToken(['events' => null])],
    'has a nonce' => [fn () => $this->logoutToken(['nonce' => 'abc'])],
    'too old' => [fn () => $this->logoutToken(['iat' => now()->subMinutes(30)->getTimestamp(), 'exp' => null])],
    'expired' => [fn () => $this->logoutToken(['exp' => now()->subMinutes(5)->getTimestamp()])],
    'alg none' => [function () {
        $parts = explode('.', $this->logoutToken(header: ['alg' => 'none']));

        return $parts[0].'.'.$parts[1].'.';
    }],
    'garbage' => ['not-a-jwt'],
    'missing subject' => [fn () => $this->logoutToken(['sub' => null])],
]);

test('a missing logout token is rejected', function () {
    $this->post(route('portal.backchannel-logout'))->assertStatus(400);
});

test('the jwks is fetched once and cached', function () {
    ($this->postLogout)($this->logoutToken())->assertOk();
    ($this->postLogout)($this->logoutToken(['sub' => 'uuid-other']))->assertOk();

    Http::assertSentCount(1);
});

test('unknown key ids cannot make the app refetch the keys over and over', function () {
    ($this->postLogout)($this->logoutToken())->assertOk();

    foreach (range(1, 5) as $i) {
        ($this->postLogout)($this->logoutToken(header: ['kid' => "made-up-{$i}"]))->assertStatus(400);
    }

    // First fetch plus at most one forced refresh per minute.
    Http::assertSentCount(2);
});

test('a failed key download is not cached', function () {
    config(['portal.issuer' => 'https://login2.texasrenters.com']);
    $pair = self::portalKeyPair();
    Http::fake(['login2.texasrenters.com/oauth/jwks' => Http::sequence()
        ->push('down', 503)
        ->push(['keys' => [['kty' => 'RSA', 'kid' => 'portal-key-1', 'n' => $pair['n'], 'e' => $pair['e']]]])]);
    Cache::flush();

    ($this->postLogout)($this->logoutToken(['iss' => 'https://login2.texasrenters.com']))->assertStatus(400);
    $this->travel(2)->minutes();
    ($this->postLogout)($this->logoutToken(['iss' => 'https://login2.texasrenters.com']))->assertOk();
});

test('while the portal is down repeated logout calls do not each hit the portal', function () {
    config(['portal.issuer' => 'https://login3.texasrenters.com']);
    Http::fake(['login3.texasrenters.com/oauth/jwks' => Http::response('down', 503)]);

    foreach (range(1, 5) as $i) {
        ($this->postLogout)($this->logoutToken(['iss' => 'https://login3.texasrenters.com']))->assertStatus(400);
    }

    Http::assertSentCount(1);
});
