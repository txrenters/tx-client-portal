<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('shows the portal welcome page to visitors', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

test('sends a signed-in client to their dashboard', function () {
    $this->actingAs(User::factory()->resident()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
