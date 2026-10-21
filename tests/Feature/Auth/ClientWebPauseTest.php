<?php

use App\Models\AdminAction;
use App\Models\ClientAccount;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\Security\ClientWebPause;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use Laravel\Passkeys\Passkeys;

const WEB_PAUSED = "We're still in beta, so sign-in isn't open yet. Please check back soon.";

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com'], 'services.propertyware.discover_accounts' => false]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    $this->client = User::factory()->create(['email' => 'wood@example.com']);
    ClientAccount::factory()->resident()->for($this->client)->create();
});

test('staff pause client sign-in from the Security page', function () {
    $this->actingAs($this->admin)->post(route('admin.security.client-web.pause'))->assertRedirect();

    expect(app(ClientWebPause::class)->isPaused())->toBeTrue()
        ->and(AdminAction::sole()->action)->toBe('client_web_paused');

    $this->get(route('admin.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clientWebPaused', true));
});

test('staff resume client sign-in', function () {
    app(ClientWebPause::class)->pause();

    $this->actingAs($this->admin)->delete(route('admin.security.client-web.resume'))->assertRedirect();

    expect(app(ClientWebPause::class)->isPaused())->toBeFalse()
        ->and(AdminAction::sole()->action)->toBe('client_web_resumed');
});

test('a client cannot pause sign-in', function () {
    $this->actingAs($this->client)->post(route('admin.security.client-web.pause'))->assertForbidden();

    expect(app(ClientWebPause::class)->isPaused())->toBeFalse();
});

test('the sign-in and sign-up pages show the beta notice only while paused', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('accessPaused', false));

    app(ClientWebPause::class)->pause();

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('accessPaused', true));
    $this->get(route('register'))->assertInertia(fn (AssertableInertia $page) => $page->where('accessPaused', true));
});

test('a client cannot sign in to the website while it is paused', function () {
    app(ClientWebPause::class)->pause();

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => WEB_PAUSED]);

    $this->assertGuest();
    expect(SignInEvent::sole())->event->toBe('blocked')->channel->toBe('web');
});

test('a client cannot sign in to the website with a passkey while it is paused', function () {
    app(ClientWebPause::class)->pause();
    $passkey = $this->client->passkeys()->create(['name' => 'Mac', 'credential_id' => 'abc', 'credential' => []]);

    expect(fn () => Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))
        ->toThrow(WEB_PAUSED);
});

test('a client signed in to the website is signed out once it is paused', function () {
    app(ClientWebPause::class)->pause();

    $this->actingAs($this->client)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', WEB_PAUSED);

    $this->assertGuest();
});

test('nobody can sign up on the website while it is paused', function () {
    app(ClientWebPause::class)->pause();

    $this->post(route('register.store'), [
        'name' => 'Dana Client',
        'email' => 'dana@example.com',
        'password' => 'A-long-passphrase-9',
        'password_confirmation' => 'A-long-passphrase-9',
    ])->assertSessionHasErrors(['email' => "We're still in beta, so sign-up isn't open yet. Please check back soon."]);

    $this->assertGuest();
    expect(User::query()->where('email', 'dana@example.com')->exists())->toBeFalse();
});

test('staff still sign in to the website while it is paused', function () {
    app(ClientWebPause::class)->pause();

    $this->post(route('login.store'), ['email' => 'staff@texasrenters.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->admin);
});

test('staff impersonating a client stay on the website while it is paused', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));
    app(ClientWebPause::class)->pause();

    $this->get(route('dashboard'))->assertRedirect(route('resident.dashboard'));

    $this->assertAuthenticatedAs($this->client);
});

test('the apps keep working while the website is paused', function () {
    app(ClientWebPause::class)->pause();
    $passkey = $this->client->passkeys()->create(['name' => 'iPhone', 'credential_id' => 'abc', 'credential' => []]);

    $this->postJson(route('api.v1.auth.login'), ['email' => 'wood@example.com', 'password' => 'password', 'device_name' => 'iphone'])
        ->assertOk()
        ->assertJsonStructure(['token']);

    expect(Passkeys::allowsLogin(Request::create('/api/v1/auth/passkeys/login', 'POST'), $passkey))->toBeTrue();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Dana Client',
        'email' => 'dana@example.com',
        'password' => 'A-long-passphrase-9',
        'password_confirmation' => 'A-long-passphrase-9',
        'device_name' => 'iphone',
    ])->assertCreated();
});
