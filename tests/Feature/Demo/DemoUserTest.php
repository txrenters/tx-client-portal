<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('a user is not a demo user unless marked', function () {
    expect(User::factory()->create()->is_demo)->toBeFalse()
        ->and(User::factory()->demo()->create()->is_demo)->toBeTrue();
});

test('the demo flag cannot be mass-assigned', function () {
    $user = User::factory()->create();

    $user->fill(['is_demo' => true])->save();

    expect($user->fresh()->is_demo)->toBeFalse();
});

test('the admin users list marks demo users', function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    User::factory()->demo()->create(['email' => 'appreview.owner@texasrenters.com']);

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'appreview']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data.0.email', 'appreview.owner@texasrenters.com')
            ->where('users.data.0.isDemo', true));
});
