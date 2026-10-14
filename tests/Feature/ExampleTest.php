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

test('gives the public pages search and share tags without needing JavaScript', function (string $route, string $title) {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/share/portal.jpg').'">', false)
        ->assertSee("<title>{$title} - ".config('app.name').'</title>', false)
        ->assertDontSee('noindex');
})->with([
    ['home', 'Client portal'],
    ['privacy', 'Privacy policy'],
    ['support', 'Support'],
]);

test('keeps the login page out of search results', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertDontSee('og:image');
});
