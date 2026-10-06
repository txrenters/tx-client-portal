<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('shows the portal welcome page to visitors', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

test('shows the welcome page to a signed-in client too', function () {
    $this->actingAs(User::factory()->resident()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

test('shows the privacy policy and support pages to visitors', function (string $route, string $component) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
})->with([
    ['privacy', 'Privacy'],
    ['support', 'Support'],
]);

test('sends the old two-factor link from the app to the security page', function () {
    $this->actingAs(User::factory()->resident()->create())
        ->get('/settings/two-factor')
        ->assertRedirect('/settings/security');
});
