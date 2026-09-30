<?php

use App\Models\ClientAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Scheduling\MeetingCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Cache::flush();
    config([
        'services.scheduling.url' => 'https://scheduling.test',
        'services.scheduling.token' => 'token',
        'services.scheduling.page' => 'texas-renters',
    ]);

    // Clients only see meeting types staff approved; approve the one faked below.
    // Written directly: resolving the catalog here would build the scheduling
    // client before tests that change its config get the chance.
    Setting::put(MeetingCatalog::SETTING, json_encode(['owner-meeting']));

    $this->owner = User::factory()->create(['name' => 'Xue Gao', 'email' => 'owner@example.com']);
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create();
});

function fakeScheduling(array $bookings = []): void
{
    Http::fake([
        '*/event-types' => Http::response(['data' => [[
            'slug' => 'owner-meeting',
            'page' => 'texas-renters',
            'name' => 'Owner meeting',
            'description' => 'A catch up about your properties.',
            'color' => '#123456',
            'durationMinutes' => 30,
            'locationType' => 'custom_link',
            'locationLabel' => 'Video call',
            'host' => 'Sam Staff',
            'url' => 'https://scheduling.test/book/texas-renters/owner-meeting',
        ]]]),
        '*/bookings*' => Http::response(['data' => $bookings]),
    ]);
}

test('an owner is sent to the departments page to choose a meeting', function () {
    fakeScheduling();
    config(['services.scheduling.booking_url' => 'https://scheduling.test/book/texas-renters/departments/abc']);

    $this->actingAs($this->owner)
        ->get(route('owner.meetings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('owner/Meetings')
            ->where('available', true)
            ->where('bookingUrl', 'https://scheduling.test/book/texas-renters/departments/abc')
            ->where('channel', "App.Models.User.{$this->owner->id}"));
});

test('the meetings come from the whole organization, not one page', function () {
    fakeScheduling();
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $this->account))->assertOk();

    // Meetings live on several pages within an organization, so asking one
    // page would quietly hide the rest.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/organizations/texas-renters/event-types'));
});

test('an owner sees what they have already booked, matched on their address', function () {
    fakeScheduling([[
        'id' => 1,
        'uid' => 'abc',
        'name' => 'Owner meeting',
        'startsAt' => '2026-10-01T14:00:00+00:00',
        'endsAt' => '2026-10-01T14:30:00+00:00',
        'timezone' => 'America/Chicago',
        'locationLabel' => 'Video call',
        'meetingUrl' => null,
        'manageUrl' => 'https://scheduling.test/booking/abc',
    ]]);

    $this->actingAs($this->owner);

    partialGet(route('owner.meetings.index'), 'owner/Meetings', 'upcoming')
        ->assertOk()
        ->assertJsonPath('props.upcoming.0.uid', 'abc');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/bookings')
        && str_contains($request->url(), 'email=owner%40example.com'));
});

test('the page says so when scheduling is not connected', function () {
    config(['services.scheduling.url' => null, 'services.scheduling.token' => null]);

    $this->actingAs($this->owner)
        ->get(route('owner.meetings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('available', false));
});

test('a scheduling outage leaves the page standing', function () {
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

    $this->actingAs($this->owner);

    partialGet(route('owner.meetings.index'), 'owner/Meetings', 'upcoming')
        ->assertOk()
        ->assertJsonPath('props.upcoming', []);
});

test('a resident cannot reach the owner meetings page', function () {
    $resident = User::factory()->create();
    ClientAccount::factory()->resident()->for($resident)->create();

    $this->actingAs($resident)->get(route('owner.meetings.index'))->assertForbidden();
});

test('the apps read the same meetings', function () {
    fakeScheduling();
    config(['services.scheduling.booking_url' => 'https://scheduling.test/book/texas-renters/departments/abc']);
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'owner-meeting')
        ->assertJsonPath('meta.available', true)
        ->assertJsonPath('meta.bookingUrl', 'https://scheduling.test/book/texas-renters/departments/abc');
});

test('the apps cannot read meetings for a resident account', function () {
    fakeScheduling();
    $resident = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($resident)->create();
    Sanctum::actingAs($resident);

    $this->getJson(route('api.v1.accounts.meetings.index', $account))->assertNotFound();
});

test('the apps cannot read meetings for another client account', function () {
    fakeScheduling();
    $other = ClientAccount::factory()->owner()->create();
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $other))->assertNotFound();
});

test('the page comes from the key, so it need not be configured twice', function () {
    config(['services.scheduling.page' => null]);

    Http::fake([
        '*/me' => Http::response(['data' => ['organization' => ['page' => '1']]]),
        '*/event-types' => Http::response(['data' => []]),
        '*/bookings*' => Http::response(['data' => []]),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $this->account))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/me'));
});

test('a key tied to no organization says the page must be configured', function () {
    config(['services.scheduling.page' => null]);
    Cache::flush();

    Http::fake([
        // A system-wide key: nothing to imply.
        '*/me' => Http::response(['data' => ['organization' => null]]),
        '*' => Http::response(['data' => []]),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $this->account))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'This key is not tied to an organization, so a booking page must be configured.');
});
