<?php

use App\Enums\StaffRole;
use App\Models\ClientAccount;
use App\Models\SignInEvent;
use App\Models\User;
use App\PortalSso\PortalUserLinker;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\PortalSso\InteractsWithPortal;

uses(InteractsWithPortal::class);

beforeEach(function () {
    $this->setUpPortal();
});

test('the redirect sends the browser to the portal with pkce', function () {
    $response = $this->get(route('portal.redirect'));

    $response->assertRedirectContains('https://login.texasrenters.com/oauth/authorize?');
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
    $expected = rtrim(strtr(base64_encode(hash('sha256', session('portal.verifier'), true)), '+/', '-_'), '=');
    expect($q['code_challenge'])->toBe($expected);
});

test('a member of staff is linked by email, signed in and sent to the admin area', function () {
    $maria = $this->staff(['email' => 'maria@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'Maria@TexasRenters.com', 'name' => 'Maria']);

    $this->finishPortalLogin()->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($maria);
    expect($maria->fresh()->portal_subject)->toBe('uuid-maria')
        ->and(session('portal.refresh_token'))->toBe('ref');
});

test('an owner listed in ADMIN_EMAILS signs in like any member of staff', function () {
    config(['services.admin_emails' => ['boss@texasrenters.com']]);
    $boss = User::factory()->create(['email' => 'boss@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-boss', 'email' => 'boss@texasrenters.com', 'name' => 'Boss']);

    $this->finishPortalLogin();

    $this->assertAuthenticatedAs($boss);
});

test('the sign-in is logged as a portal sign-in', function () {
    $this->staff(['email' => 'maria@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

    $this->finishPortalLogin();

    expect(SignInEvent::query()->latest('id')->first())
        ->event->toBe('signed_in')
        ->method->toBe('portal');
});

test('a linked member of staff is found by portal subject even if their email changed', function () {
    $maria = $this->staff(['email' => 'old@texasrenters.com', 'portal_subject' => 'uuid-maria']);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

    $this->finishPortalLogin();

    $this->assertAuthenticatedAs($maria);
});

test('someone without an account here is refused and nothing is created', function () {
    // Owner decision: no automatic accounts; an admin adds the person on the Staff page first.
    $this->portalReturns(['sub' => 'uuid-new', 'email' => 'new@texasrenters.com', 'name' => 'New']);

    $this->finishPortalLogin()
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => PortalUserLinker::NO_STAFF_ACCESS]);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a company address on a resident account is refused and given no staff role', function () {
    $resident = $this->resident(['email' => 'maria@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

    $this->finishPortalLogin()
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => PortalUserLinker::NO_STAFF_ACCESS]);

    $this->assertGuest();
    expect($resident->fresh())
        ->staff_role->toBeNull()
        ->portal_subject->toBeNull();
});

test('a linked account whose staff access was removed is refused', function () {
    $this->staff(['email' => 'gone@texasrenters.com', 'portal_subject' => 'uuid-gone', 'staff_role' => null]);
    $this->portalReturns(['sub' => 'uuid-gone', 'email' => 'gone@texasrenters.com', 'name' => 'Gone']);

    $this->finishPortalLogin()
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => PortalUserLinker::NO_STAFF_ACCESS]);

    $this->assertGuest();
});

test('a suspended member of staff is refused', function () {
    $this->staff(['email' => 'maria@texasrenters.com', 'suspended_at' => now()]);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

    $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SignInEvent::query()->latest('id')->first()->event)->toBe('blocked');
});

test('the staff role, client accounts and details are never changed', function () {
    $admin = $this->staff(['email' => 'boss@texasrenters.com', 'name' => 'The Boss', 'staff_role' => StaffRole::Admin]);
    ClientAccount::factory()->owner()->for($admin)->create();
    $this->portalReturns(['sub' => 'uuid-boss', 'email' => 'boss@texasrenters.com', 'name' => 'Someone Else']);

    $this->finishPortalLogin();

    $admin = $admin->fresh();
    expect($admin->name)->toBe('The Boss')
        ->and($admin->staff_role)->toBe(StaffRole::Admin)
        ->and($admin->clientAccounts()->count())->toBe(1);
});

test('a wrong state is refused before any token request', function () {
    Http::fake();
    $this->startPortalLogin();

    $this->get(route('portal.callback', ['code' => 'c', 'state' => 'forged']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    Http::assertNothingSent();
});

test('a state cannot be replayed', function () {
    $this->staff(['email' => 'maria@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'M']);
    $state = $this->startPortalLogin();
    $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]));
    auth()->logout();

    $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('someone the portal has not given this app is refused', function () {
    $this->staff(['email' => 'x@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-x', 'email' => 'x@texasrenters.com', 'name' => 'X', 'apps' => ['cma']]);

    $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an email already linked to another portal person is refused', function () {
    $this->staff(['email' => 'maria@texasrenters.com', 'portal_subject' => 'uuid-original']);
    $this->portalReturns(['sub' => 'uuid-other', 'email' => 'maria@texasrenters.com', 'name' => 'M']);

    $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an email that only matches under the database collation is never linked', function () {
    $lookalike = $this->staff(['email' => 'josé@texasrenters.com']);
    $this->portalReturns(['sub' => 'uuid-jose', 'email' => 'jose@texasrenters.com', 'name' => 'J']);

    $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($lookalike->fresh()->portal_subject)->toBeNull();
});

test('an email the portal has not verified is never used to link', function (array $claims) {
    $maria = $this->staff(['email' => 'maria@texasrenters.com']);
    $this->portalReturns(array_merge(['sub' => 'uuid-x', 'name' => 'X'], $claims));

    $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($maria->fresh()->portal_subject)->toBeNull();
})->with([
    'unverified' => [['email' => 'maria@texasrenters.com', 'email_verified' => false]],
    'empty email' => [['email' => '', 'email_verified' => true]],
    'outside company domains' => [['email' => 'maria@gmail.com', 'email_verified' => true]],
]);

test('staff password login is refused while the portal is on', function () {
    $maria = $this->staff();

    $this->post('/login', ['email' => $maria->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the password form never reveals a staff address or password', function () {
    $maria = $this->staff();
    $resident = $this->resident();

    $error = function (string $email, string $password): ?string {
        $errors = $this->post('/login', ['email' => $email, 'password' => $password])->getSession()->get('errors');
        // The session store serialises the error bag to an array here.
        $message = data_get($errors, 'default.messages.email.0');
        auth()->logout();

        return $message;
    };

    $staffRight = $error($maria->email, 'password');
    expect($staffRight)->not->toBeNull()
        ->and($error($maria->email, 'not-it'))->toBe($staffRight)
        ->and($error('nobody@texasrenters.com', 'password'))->toBe($staffRight)
        ->and($error($resident->email, 'not-it'))->toBe($staffRight);
});

test('resident password login is unchanged while the portal is on', function () {
    $resident = $this->resident();

    $this->post('/login', ['email' => $resident->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($resident);
});

test('the emergency switch brings staff password login back', function () {
    config(['portal.allow_password_login' => true]);
    $maria = $this->staff();

    $this->post('/login', ['email' => $maria->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($maria);
});

test('with the portal disabled nothing changes', function () {
    config(['portal.enabled' => false]);
    $maria = $this->staff();

    $this->get(route('portal.redirect'))->assertNotFound();
    $this->post('/login', ['email' => $maria->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($maria);
});

test('the login page offers the portal link only while the portal is on', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('portalEnabled', true));

    config(['portal.enabled' => false]);

    $this->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('portalEnabled', false));
});

test('staff who came through the portal sign out back to the portal', function () {
    $maria = $this->staff();

    $this->actingAs($maria)
        ->withSession($this->portalSession(0))
        ->post('/logout')
        ->assertRedirect('https://login.texasrenters.com/dashboard');

    $this->assertGuest();
});

test('residents signing out are unchanged', function () {
    $this->actingAs($this->resident())->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
