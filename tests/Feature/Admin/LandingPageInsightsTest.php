<?php

use App\Models\LandingPageEvent;
use App\Models\User;
use App\Services\Admin\LandingPageTracker;
use Inertia\Testing\AssertableInertia;

const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';

beforeEach(function () {
    $this->withHeader('User-Agent', BROWSER);
});

function landingEvents(string $event): int
{
    return LandingPageEvent::query()->where('event', $event)->count();
}

test('counts each landing page visitor once a day, and again the next day', function () {
    $this->get(route('home'))->assertOk();
    $this->get(route('home'))->assertOk();

    expect(landingEvents(LandingPageTracker::VIEW))->toBe(1);

    $this->travel(1)->days();
    $this->get(route('home'))->assertOk();

    expect(landingEvents(LandingPageTracker::VIEW))->toBe(2)
        ->and(LandingPageEvent::query()->distinct()->count('visitor'))->toBe(2);
});

test('stores no address or browser, only a hash', function () {
    $this->get(route('home'));

    $stored = LandingPageEvent::query()->sole();

    expect($stored->visitor)->toMatch('/^[a-f0-9]{64}$/')
        ->and(json_encode($stored->toArray()))->not->toContain('127.0.0.1')->not->toContain('Safari');
});

test('leaves out signed-in clients, crawlers and prefetches', function () {
    $this->actingAs(User::factory()->create())->get(route('home'));
    auth()->logout();
    $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')->get(route('home'));
    $this->withHeaders(['User-Agent' => BROWSER, 'Purpose' => 'prefetch'])->get(route('home'));

    expect(landingEvents(LandingPageTracker::VIEW))->toBe(0);
});

test('counts a tutorial play only from someone who opened the landing page', function () {
    $this->postJson(route('landing.events'), ['event' => 'tutorial_play'])->assertNoContent();
    expect(landingEvents(LandingPageTracker::TUTORIAL_PLAY))->toBe(0);

    $this->get(route('home'));
    $this->postJson(route('landing.events'), ['event' => 'tutorial_play'])->assertNoContent();
    $this->postJson(route('landing.events'), ['event' => 'tutorial_play'])->assertNoContent();

    expect(landingEvents(LandingPageTracker::TUTORIAL_PLAY))->toBe(1);

    $this->postJson(route('landing.events'), ['event' => 'sign_up'])->assertUnprocessable();
});

test('counts a sign-up from the landing page as a sign-up, not also a sign-in', function () {
    $this->get(route('home'));

    $this->post(route('register.store'), [
        'name' => 'New Client',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(landingEvents(LandingPageTracker::SIGN_UP))->toBe(1)
        ->and(landingEvents(LandingPageTracker::SIGN_IN))->toBe(0);
});

test('counts a sign-in only from someone who opened the landing page that day', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    auth()->logout();
    expect(landingEvents(LandingPageTracker::SIGN_IN))->toBe(0);

    $this->get(route('home'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(landingEvents(LandingPageTracker::SIGN_IN))->toBe(1);
});

test('shows the landing page figures on Insights', function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    $today = now(config('app.display_timezone'))->toDateString();

    foreach (['a', 'b', 'c', 'd'] as $visitor) {
        LandingPageEvent::create(['day' => $today, 'event' => 'view', 'visitor' => str_repeat($visitor, 64)]);
    }
    LandingPageEvent::create(['day' => $today, 'event' => 'tutorial_play', 'visitor' => str_repeat('a', 64)]);
    LandingPageEvent::create(['day' => $today, 'event' => 'sign_up', 'visitor' => str_repeat('b', 64)]);
    LandingPageEvent::create(['day' => now()->subDays(40)->toDateString(), 'event' => 'view', 'visitor' => str_repeat('e', 64)]);

    $this->actingAs($admin)->get(route('admin.insights'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('landing.visitors', 4)
            ->where('landing.tutorialPlays', 1)
            ->where('landing.signUps', 1)
            ->where('landing.signIns', 0)
            ->where('landing.daily.29', ['date' => $today, 'visitors' => 4]));
});

test('forgets landing page events after the keep period', function () {
    LandingPageEvent::create(['day' => now()->subDays(LandingPageEvent::KEEP_DAYS + 1)->toDateString(), 'event' => 'view', 'visitor' => str_repeat('a', 64)]);
    LandingPageEvent::create(['day' => now()->toDateString(), 'event' => 'view', 'visitor' => str_repeat('b', 64)]);

    $this->artisan('model:prune', ['--model' => LandingPageEvent::class]);

    expect(LandingPageEvent::query()->count())->toBe(1);
});
