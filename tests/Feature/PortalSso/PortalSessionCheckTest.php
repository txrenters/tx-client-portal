<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\PortalSso\PortalSignOuts;
use Illuminate\Support\Facades\Http;
use Tests\Feature\PortalSso\InteractsWithPortal;

uses(InteractsWithPortal::class);

beforeEach(function () {
    $this->setUpPortal();
    $this->user = $this->staff(['portal_subject' => 'uuid-1']);
    // A page every signed-in account can open.
    $this->home = route('profile.edit');
});

test('a recent check does not call the portal', function () {
    Http::fake();

    $this->actingAs($this->user)->withSession($this->portalSession(1))->get($this->home)->assertOk();

    Http::assertNothingSent();
});

test('an old check refreshes and keeps the new refresh token', function () {
    Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'ref-2', 'expires_in' => 600])]);

    $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home)->assertOk();

    expect(session('portal.refresh_token'))->toBe('ref-2')
        ->and(session('portal.checked_at'))->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

test('revoked access signs the person out and sends them through the portal', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home)
        ->assertRedirect(route('portal.redirect'));

    $this->assertGuest();
});

test('a portal outage does not sign anyone out', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home)->assertOk();

    $this->assertAuthenticatedAs($this->user);
});

test('a staff password session is ended once the portal is on', function () {
    $this->actingAs($this->user)->get($this->home)->assertRedirect(route('portal.redirect'));

    $this->assertGuest();
});

test('staff password sessions are allowed while the emergency switch is on', function () {
    config(['portal.allow_password_login' => true]);

    $this->actingAs($this->user)->get($this->home)->assertOk();
});

test('nothing is checked while the portal is disabled', function () {
    config(['portal.enabled' => false]);
    Http::fake();

    $this->actingAs($this->user)->get($this->home)->assertOk();

    Http::assertNothingSent();
});

test('a resident session is never checked or ended', function () {
    Http::fake();
    $resident = $this->resident();

    $location = $this->actingAs($resident)->get($this->home)->headers->get('Location');

    expect((string) $location)->not->toContain('auth/portal');
    $this->assertAuthenticatedAs($resident);
    Http::assertNothingSent();
});

test('two requests at the five minute mark do not sign the person out', function () {
    // The portal rotates refresh tokens: a second request holding the old one must
    // follow the rotation, not present a revoked token.
    $calls = 0;
    Http::fake(function ($request) use (&$calls) {
        $calls++;

        return $request['refresh_token'] === 'ref-1' && $calls === 1
            ? Http::response(['access_token' => 'a', 'refresh_token' => 'ref-2', 'expires_in' => 600])
            : Http::response(['error' => 'invalid_grant'], 400);
    });

    $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home)->assertOk();
    $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home)->assertOk();

    $this->assertAuthenticatedAs($this->user);
    expect($calls)->toBe(1)
        ->and(session('portal.refresh_token'))->toBe('ref-2');
});

test('a page request whose session ended asks inertia to reload through the portal', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs($this->user)->withSession($this->portalSession(6))
        ->get($this->home, [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('portal.redirect'));
});

test('a background json request whose session ended gets 401', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs($this->user)->withSession($this->portalSession(6))->getJson($this->home)->assertUnauthorized();
});

test('a session the portal signed out after it began ends on its next request', function () {
    Http::fake();
    PortalSignOuts::record('uuid-1');

    $this->actingAs($this->user)->withSession($this->portalSession(1))->get($this->home)
        ->assertRedirect(route('portal.redirect'));

    $this->assertGuest();
    Http::assertNothingSent();
});

test('a portal sign-in made after a sign-out is not ended by it', function () {
    Http::fake();
    PortalSignOuts::record('uuid-1');
    $this->travel(1)->minute();

    $this->actingAs($this->user)
        ->withSession(array_merge($this->portalSession(0), ['portal.signed_in_at' => now()->getTimestamp()]))
        ->get($this->home)
        ->assertOk();

    $this->assertAuthenticatedAs($this->user);
});

describe('while an admin is signed in as a client', function () {
    beforeEach(function () {
        $this->client = $this->resident();
        $this->actingAs($this->client)->withSession(array_merge($this->portalSession(1), [
            ImpersonationController::SESSION_KEY => $this->user->id,
        ]));
    });

    test('the visit keeps going while the admin still has access', function () {
        Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'ref-2', 'expires_in' => 600])]);
        $this->travel(6)->minutes();

        $this->get($this->home)->assertOk();

        $this->assertAuthenticatedAs($this->client);
        expect(session('portal.refresh_token'))->toBe('ref-2');
    });

    test('the visit ends once the portal revokes the admin', function () {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->travel(6)->minutes();

        $this->get($this->home)->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    });

    test('the visit ends once the portal signs the admin out everywhere', function () {
        Http::fake();
        PortalSignOuts::record('uuid-1');

        $this->get($this->home)->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    });
});
