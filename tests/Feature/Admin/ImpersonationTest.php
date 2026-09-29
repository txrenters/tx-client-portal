<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\ClientAccount;
use App\Models\User;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com', 'other-staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    $this->client = User::factory()->create(['email' => 'resident@example.com']);
    ClientAccount::factory()->resident()->for($this->client)->create();
});

test('an admin can sign in as a client and land on their portal', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.impersonate', $this->client))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($this->client->id)
        ->and(session(ImpersonationController::SESSION_KEY))->toBe($this->admin->id);
});

test('the client sees their own portal while being impersonated', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));

    $this->get(route('resident.dashboard'))->assertOk();
});

test('an impersonated session is not an admin', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));

    // Otherwise signing in as a client would hand them staff access.
    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('the admin can take their own account back', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));

    $this->delete(route('impersonate.stop'))
        ->assertRedirect(route('admin.users.show', $this->client));

    expect(auth()->id())->toBe($this->admin->id)
        ->and(session()->has(ImpersonationController::SESSION_KEY))->toBeFalse();
});

test('staff accounts cannot be impersonated', function () {
    $other = User::factory()->create(['email' => 'other-staff@texasrenters.com']);

    // Otherwise this becomes a way to borrow another admin's access.
    $this->actingAs($this->admin)
        ->post(route('admin.users.impersonate', $other))
        ->assertRedirect();

    expect(auth()->id())->toBe($this->admin->id);
});

test('impersonation cannot be chained', function () {
    $second = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));
    $this->post(route('admin.users.impersonate', $second))->assertForbidden();

    // Still the first client, and the way back still points at the admin.
    expect(auth()->id())->toBe($this->client->id)
        ->and(session(ImpersonationController::SESSION_KEY))->toBe($this->admin->id);
});

test('a client cannot impersonate anyone', function () {
    $victim = User::factory()->create();

    $this->actingAs($this->client)
        ->post(route('admin.users.impersonate', $victim))
        ->assertForbidden();

    expect(auth()->id())->toBe($this->client->id);
});

test('stopping does nothing when nobody is being impersonated', function () {
    $this->actingAs($this->client)
        ->delete(route('impersonate.stop'))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($this->client->id);
});

test('every page says when it is being viewed as someone else', function () {
    $this->actingAs($this->admin)->post(route('admin.users.impersonate', $this->client));

    $this->get(route('resident.dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.impersonating', true));

    $this->delete(route('impersonate.stop'));

    $this->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.impersonating', false));
});
