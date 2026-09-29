<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\ClientInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

test('insights list who needs a follow-up', function () {
    User::factory()->unverified()->create(['email' => 'stuck@example.com', 'created_at' => now()->subDays(3)]);
    User::factory()->create(['email' => 'empty@example.com']);
    User::factory()->resident()->create(['email' => 'gone@example.com', 'last_seen_at' => now()->subDays(45)]);
    User::factory()->resident()->create(['email' => 'here@example.com', 'last_seen_at' => now()]);
    ClientInvitation::factory()->expired()->create(['email' => 'never@example.com']);
    ClientInvitation::factory()->expired()->create(['email' => 'here@example.com']);

    $this->actingAs($this->admin)->get(route('admin.insights'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Insights')
            ->where('unconfirmed.people.0.email', 'stuck@example.com')
            ->where('unlinked.total', 2) // empty@ and the admin, who holds no client account
            ->where('quiet.total', 1)
            ->where('quiet.people.0.email', 'gone@example.com')
            ->where('adoption.clients', 2)
            ->where('adoption.activeThisMonth', 1)
            ->has('unaccepted', 1)
            ->where('unaccepted.0.email', 'never@example.com'));
});

test('using the portal records when someone was last seen', function () {
    $client = User::factory()->create();

    $this->actingAs($client)->get(route('help'));

    expect($client->fresh()->last_seen_at)->not->toBeNull();
});

test('an admin signed in as a client does not count as the client coming back', function () {
    $client = User::factory()->create();

    $this->actingAs($client)
        ->withSession([ImpersonationController::SESSION_KEY => $this->admin->id])
        ->get(route('help'));

    expect($client->fresh()->last_seen_at)->toBeNull();
});
