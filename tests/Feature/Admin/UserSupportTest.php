<?php

use App\Models\AdminAction;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com', 'other-staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    $this->client = User::factory()->create(['email' => 'dana@example.com']);
});

test('the user page shows their accounts, app sessions and what staff have done', function () {
    ClientAccount::factory()->owner()->for($this->client)->create(['label' => '12 Elm St']);
    $this->client->createToken('Dana’s iPhone');
    $this->actingAs($this->admin)->post(route('admin.users.password-reset', $this->client));

    $this->actingAs($this->admin)->get(route('admin.users.show', $this->client))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/UserShow')
            ->where('user.email', 'dana@example.com')
            ->where('user.accounts.0.label', '12 Elm St')
            ->where('user.sessions.0.name', 'Dana’s iPhone')
            ->where('isStaff', false)
            ->where('history.0.summary', 'Sent dana@example.com a password reset link')
            ->where('history.0.actor', 'staff@texasrenters.com'));
});

test('the user list narrows to suspended or unconfirmed people', function () {
    User::factory()->unverified()->create(['email' => 'new@example.com']);
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->admin)->get(route('admin.users.index', ['filter' => 'suspended']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('users.total', 1)->where('users.data.0.email', 'dana@example.com'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['filter' => 'unverified']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('users.total', 1)->where('users.data.0.email', 'new@example.com'));
});

test('suspending someone signs them out of the apps and is recorded with the reason', function () {
    $this->client->createToken('iphone');

    $this->actingAs($this->admin)
        ->post(route('admin.users.suspend', $this->client), ['reason' => 'Asked us to lock it'])
        ->assertRedirect();

    expect($this->client->fresh()->isSuspended())->toBeTrue()
        ->and($this->client->tokens()->count())->toBe(0);

    $action = AdminAction::sole();
    expect($action)
        ->action->toBe('suspended')
        ->admin_id->toBe($this->admin->id)
        ->user_id->toBe($this->client->id)
        ->details->toBe(['reason' => 'Asked us to lock it']);
});

test('a suspended person is signed out of an open browser session', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->client)->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a suspended person cannot sign in on the web', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->post(route('login.store'), ['email' => 'dana@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been suspended. Contact TexasRenters.com for help.']);

    $this->assertGuest();
});

test('a suspended person cannot get an app token', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->postJson(route('api.v1.auth.login'), ['email' => 'dana@example.com', 'password' => 'password', 'device_name' => 'iphone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'This account has been suspended.']);
});

test('an app token issued before the suspension stops working', function () {
    $token = $this->client->createToken('iphone')->plainTextToken;
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();

    expect($this->client->tokens()->count())->toBe(0);
});

test('lifting a suspension lets them sign in again', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->admin)->post(route('admin.users.restore', $this->client))->assertRedirect();

    expect($this->client->fresh()->isSuspended())->toBeFalse();
    auth()->logout();
    $this->post(route('login.store'), ['email' => 'dana@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($this->client);
});

test('staff logins cannot be suspended or have two-factor reset from the admin area', function (string $route) {
    $otherAdmin = User::factory()->withTwoFactor()->create(['email' => 'other-staff@texasrenters.com']);

    $this->actingAs($this->admin)->post(route($route, $otherAdmin))->assertRedirect();
    $this->actingAs($this->admin)->post(route($route, $this->admin))->assertRedirect();

    expect($otherAdmin->fresh())
        ->suspended_at->toBeNull()
        ->two_factor_confirmed_at->not->toBeNull()
        ->and($this->admin->fresh()->suspended_at)->toBeNull()
        ->and(AdminAction::count())->toBe(0);
})->with(['admin.users.suspend', 'admin.users.two-factor-reset']);

test('a suspended person cannot be impersonated', function () {
    $this->client->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client))->assertRedirect();

    $this->assertAuthenticatedAs($this->admin);
});

test('staff can resend the confirmation email to someone unconfirmed', function () {
    Notification::fake();
    $unconfirmed = User::factory()->unverified()->create();

    $this->actingAs($this->admin)->post(route('admin.users.verification.resend', $unconfirmed))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.users.verification.resend', $this->client))->assertRedirect();

    Notification::assertSentTo($unconfirmed, VerifyEmail::class);
    Notification::assertNotSentTo($this->client, VerifyEmail::class);
});

test('staff can mark an email as confirmed', function () {
    $unconfirmed = User::factory()->unverified()->create();

    $this->actingAs($this->admin)->post(route('admin.users.mark-verified', $unconfirmed))->assertRedirect();

    expect($unconfirmed->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(AdminAction::sole()->action)->toBe('email_marked_verified');
});

test('staff can turn off two-factor for someone who lost their phone', function () {
    $locked = User::factory()->withTwoFactor()->create();

    $this->actingAs($this->admin)->post(route('admin.users.two-factor-reset', $locked))->assertRedirect();

    expect($locked->fresh())
        ->two_factor_secret->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
});

test('support actions report back through a toast', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.users.password-reset', $this->client))
        ->assertInertiaFlash('toast.message', 'A reset link is on its way to dana@example.com.');
});

test('clients cannot use the new support actions', function (string $route) {
    $this->actingAs($this->client)->post(route($route, $this->admin))->assertForbidden();

    expect($this->admin->fresh()->suspended_at)->toBeNull();
})->with([
    'admin.users.suspend',
    'admin.users.restore',
    'admin.users.two-factor-reset',
    'admin.users.mark-verified',
    'admin.users.verification.resend',
]);
