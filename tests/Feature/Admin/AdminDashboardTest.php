<?php

use App\Models\ClientAccount;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

test('the dashboard counts users, accounts and app sessions', function () {
    $client = User::factory()->create(['email_verified_at' => null]);
    ClientAccount::factory()->owner()->for($client)->create();
    ClientAccount::factory()->resident()->for($client)->create();
    $client->createToken('iphone');

    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.users.total', 2)
            ->where('stats.users.unverified', 1)
            ->where('stats.accounts.total', 2)
            ->where('stats.accounts.owners', 1)
            ->where('stats.accounts.residents', 1)
            ->where('stats.sessions.mobileTokens', 1)
            ->has('stats.signups', 30));
});

test('the user list finds someone by name or email', function () {
    User::factory()->create(['name' => 'Dana Holt', 'email' => 'dana@example.com']);
    User::factory()->create(['name' => 'Other Person', 'email' => 'other@example.com']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'dana']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.total', 1)
            ->where('users.data.0.email', 'dana@example.com'));
});

test('the user search ignores case, which Postgres does not do on its own', function () {
    User::factory()->create(['name' => 'Dana Holt', 'email' => 'Dana.Holt@Example.com']);

    // "like" is case-sensitive on Postgres while the suite runs SQLite, so the
    // query lowers both sides. Searching in the wrong case must still match.
    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'DANA.holt']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('users.total', 1));

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'dana holt']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('users.total', 1));
});

test('the user list says how many accounts and app sessions someone has', function () {
    $client = User::factory()->create(['email' => 'dana@example.com']);
    ClientAccount::factory()->owner()->for($client)->create();
    $client->createToken('iphone');

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'dana']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.appSessions', 1)
            ->has('users.data.0.accounts', 1)
            ->where('users.data.0.accounts.0.role', 'owner'));
});

test('an admin can send a password reset, using the same link a client would get', function () {
    Notification::fake();
    $client = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.password-reset', $client))
        ->assertRedirect();

    Notification::assertSentTo($client, ResetPassword::class);
});

test('signing someone out of the apps revokes their tokens and nothing else', function () {
    $client = User::factory()->create();
    $client->createToken('iphone');
    $client->createToken('android');
    $other = User::factory()->create();
    $other->createToken('iphone');

    $this->actingAs($this->admin)
        ->post(route('admin.users.revoke-app-sessions', $client))
        ->assertRedirect();

    expect($client->fresh()->tokens()->count())->toBe(0)
        // The person is signed out, not deleted, and nobody else is touched.
        ->and($client->fresh())->not->toBeNull()
        ->and($other->fresh()->tokens()->count())->toBe(1);
});

test('re-linking forgets the propertyware links without deleting the person', function () {
    $client = User::factory()->create();
    ClientAccount::factory()->owner()->for($client)->create();
    ClientAccount::factory()->resident()->for($client)->create();
    $other = User::factory()->create();
    ClientAccount::factory()->owner()->for($other)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.relink-accounts', $client))
        ->assertRedirect();

    expect(ClientAccount::where('user_id', $client->id)->count())->toBe(0)
        ->and($client->fresh())->not->toBeNull()
        ->and(ClientAccount::where('user_id', $other->id)->count())->toBe(1);
});

test('the activity page reports what has happened', function () {
    $client = User::factory()->create(['email' => 'dana@example.com']);
    ClientAccount::factory()->owner()->for($client)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.activity'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Activity'));
});

test('the activity feed shows who signed in, but not failed attempts', function () {
    $client = User::factory()->create(['email' => 'dana@example.com']);
    SignInEvent::create(['user_id' => $client->id, 'email' => 'dana@example.com', 'event' => 'signed_in', 'channel' => 'web', 'method' => 'password']);
    SignInEvent::create(['user_id' => $client->id, 'email' => 'dana@example.com', 'event' => 'signed_in', 'channel' => 'app', 'method' => 'password']);
    SignInEvent::create(['email' => 'nobody@example.com', 'event' => 'failed', 'channel' => 'web', 'method' => 'password']);

    $this->actingAs($this->admin)
        ->get(route('admin.activity'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('activity', function ($events) {
            $signIns = collect($events)->where('type', 'signed_in');

            return $signIns->pluck('summary')->sort()->values()->all() === ['Signed in on the web', 'Signed in to the app']
                && $signIns->every(fn ($event) => $event['actor'] === 'dana@example.com')
                && collect($events)->doesntContain('actor', 'nobody@example.com');
        })));
});

test('the activity feed leaves out the App Review logins signing in', function () {
    $demo = User::factory()->create(['is_demo' => true]);
    SignInEvent::create(['user_id' => $demo->id, 'email' => $demo->email, 'event' => 'signed_in', 'channel' => 'app']);

    $this->actingAs($this->admin)
        ->get(route('admin.activity'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('activity', fn ($events) => collect($events)->doesntContain('type', 'signed_in'))));
});

test('the activity feed shows who used the mobile app', function () {
    $client = User::factory()->create(['email' => 'dana@example.com']);
    $client->createToken('iphone')->accessToken->forceFill(['last_used_at' => now()])->save();
    $demo = User::factory()->demo()->create();
    $demo->createToken('iphone')->accessToken->forceFill(['last_used_at' => now()])->save();

    $this->actingAs($this->admin)
        ->get(route('admin.activity'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($page) => $page->where('activity', fn ($events) => collect($events)->where('type', 'app_session')->pluck('actor')->all() === ['dana@example.com'])));
});
