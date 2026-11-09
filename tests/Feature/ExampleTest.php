<?php

use App\Models\User;
use App\Services\Security\ClientWebPause;
use Inertia\Testing\AssertableInertia;

test('shows the portal welcome page to visitors', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

test('tells the welcome page when client sign-in on the website is paused', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('isWebPortalOpen', true));

    app(ClientWebPause::class)->pause();

    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('isWebPortalOpen', false));
});

test('offers the app on the App Store from the public pages', function () {
    $this->get(route('home'))
        ->assertSee('<meta name="apple-itunes-app" content="app-id=6818547029">', false);
});

test('sends a phone scanning the landing page QR code to its own app store', function (string $userAgent, string $store) {
    $this->withHeader('User-Agent', $userAgent)
        ->get(route('get-app'))
        ->assertRedirect($store);
})->with([
    'Android' => ['Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36', 'https://play.google.com/store/apps/details?id=com.texasrenters.portal'],
    'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 Version/18.1 Mobile/15E148 Safari/604.1', 'https://apps.apple.com/us/app/texasrenters-app/id6818547029'],
]);

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
    ['home', 'The app for your rental'],
    ['privacy', 'Privacy policy'],
    ['support', 'Support'],
]);

test('keeps the login page out of search results', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertDontSee('og:image');
});
