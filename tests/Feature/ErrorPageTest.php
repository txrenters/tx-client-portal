<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_test/forbidden', fn () => abort(403));
        Route::get('/_test/broken', fn () => throw new RuntimeException('Boom'));
        Route::get('/_test/maintenance', fn () => throw new HttpException(503));
        Route::post('/_test/form', fn () => throw new TokenMismatchException);
    });
});

test('a page that does not exist shows the portal\'s own 404 page', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            // Shared data, so the header knows who is signed in.
            ->has('auth'));
});

test('the error page knows a signed-in client', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('auth.user.id', $user->id));
});

test('forbidden pages use the error page', function () {
    $this->get('/_test/forbidden')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 403));
});

test('a server error shows the error page in production', function () {
    config(['app.debug' => false]);

    $this->get('/_test/broken')
        ->assertServerError()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 500));

    $this->get('/_test/maintenance')
        ->assertServiceUnavailable()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 503));
});

test('a server error keeps the debug page while debugging', function () {
    config(['app.debug' => true]);

    $this->get('/_test/broken')
        ->assertServerError()
        ->assertHeaderMissing('X-Inertia');

    expect($this->get('/_test/broken')->getContent())->not->toContain('"component":"Error"');
});

test('the api keeps answering errors as json', function () {
    $this->getJson('/api/v1/no-such-endpoint')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('an expired form sends the person back with a toast instead of an error page', function () {
    $this->from('/settings/profile')
        ->post('/_test/form')
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('posting to an address that does not exist is still a 404', function () {
    $this->post('/no-such-page')->assertNotFound();
});
