<?php

use App\Models\ClientAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Scheduling\MeetingCatalog;
use Illuminate\Http\Client\Request;
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
        '*/contacts/*' => Http::response(['id' => 1, 'workPhone' => '713-555-0101', 'mobilePhone' => ' ', 'homePhone' => '713-555-0303']),
    ]);
}

test('the booking form gets the owner\'s number from Propertyware', function () {
    fakeScheduling();

    // The mobile number is blank, so the work number is next in line.
    $this->actingAs($this->owner)
        ->get(route('owner.meetings.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->where('phone', '713-555-0101')));
});

test('a Propertyware outage leaves the booking form without a number', function () {
    Http::fake([
        '*/contacts/*' => Http::response(['message' => 'down'], 503),
        '*' => Http::response(['data' => []]),
    ]);

    $this->actingAs($this->owner)
        ->get(route('owner.meetings.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->where('phone', null)));
});

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

/**
 * Scheduling with the approved meeting, a week of open times, and a booking
 * endpoint that answers with the given status.
 */
function fakeBookableScheduling(int $bookingStatus = 201): void
{
    Http::fake([
        '*/event-types' => Http::response(['data' => [
            ['slug' => 'owner-meeting', 'page' => 'accounting-team', 'name' => 'Owner meeting', 'durationMinutes' => 30, 'locationLabel' => 'Phone call', 'host' => 'Accounting', 'url' => 'https://scheduling.test/book/accounting-team/owner-meeting'],
            // Not approved for clients.
            ['slug' => 'job-interview', 'page' => 'hr', 'name' => 'Job interview', 'durationMinutes' => 45, 'url' => 'https://scheduling.test/book/hr/job-interview'],
        ]]),
        '*/event-types/*/slots*' => Http::response(['data' => [
            ['startsAt' => '2026-11-20T16:40:00+00:00', 'endsAt' => '2026-11-20T17:10:00+00:00', 'seatsRemaining' => 1],
        ], 'meta' => ['durationMinutes' => 30]]),
        '*/bookings' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response($bookingStatus === 409
                ? ['message' => 'That time has just been taken.']
                : ['data' => ['id' => 9, 'uid' => 'bk-9', 'name' => 'Owner meeting', 'startsAt' => '2026-11-20T16:40:00+00:00', 'endsAt' => '2026-11-20T17:10:00+00:00', 'timezone' => 'America/Chicago', 'locationLabel' => 'Phone call', 'meetingUrl' => null, 'manageUrl' => 'https://scheduling.test/bookings/bk-9']], $bookingStatus)
            : Http::response(['data' => []]),
    ]);
}

test('an owner sees the open times for an approved meeting', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();

    $this->actingAs($this->owner)
        ->getJson(route('owner.meetings.slots', ['owner-meeting', 'from' => '2026-11-19', 'to' => '2026-11-25']))
        ->assertOk()
        ->assertJsonPath('data.0.startsAt', '2026-11-20T16:40:00+00:00');

    // Asked on the meeting's own booking page, for exactly those days.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/pages/accounting-team/event-types/owner-meeting/slots')
        && $request['from'] === '2026-11-19' && $request['to'] === '2026-11-25');
});

test('an owner books a meeting in their own name, whatever the request says', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();

    $this->actingAs($this->owner)
        ->post(route('owner.meetings.store'), [
            'event_type' => 'owner-meeting',
            'starts_at' => '2026-11-20T16:40:00+00:00',
            'notes' => 'About the November statement.',
            'timezone' => 'America/Chicago',
            'name' => 'Someone Else',
            'email' => 'someone@example.com',
        ])
        ->assertRedirect(route('owner.meetings.index'))
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/bookings')
        && $request['page'] === 'accounting-team'
        && $request['event_type'] === 'owner-meeting'
        && $request['starts_at'] === '2026-11-20T16:40:00+00:00'
        && $request['name'] === 'Xue Gao'
        && $request['email'] === 'owner@example.com'
        && $request['notes'] === 'About the November statement.'
        && $request['timezone'] === 'America/Chicago');
});

test('an owner is asked to pick another time when someone else booked it first', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling(409);

    $this->actingAs($this->owner)
        ->from(route('owner.meetings.index'))
        ->post(route('owner.meetings.store'), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-20T16:40:00+00:00'])
        ->assertRedirect(route('owner.meetings.index'))
        ->assertSessionHasErrors(['starts_at' => 'That time has just been taken.']);
});

test('a meeting staff have not approved can be neither read nor booked', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();
    $this->actingAs($this->owner);

    $this->getJson(route('owner.meetings.slots', ['job-interview', 'from' => '2026-11-19', 'to' => '2026-11-25']))->assertNotFound();
    $this->post(route('owner.meetings.store'), ['event_type' => 'job-interview', 'starts_at' => '2026-11-20T16:40:00+00:00'])->assertNotFound();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'job-interview') || $request->method() === 'POST');
});

test('open times are asked for from today, a month at most', function (array $query, string $field, string $message) {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();

    $this->actingAs($this->owner)
        ->getJson(route('owner.meetings.slots', ['owner-meeting', ...$query]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'a day already gone' => [['from' => '2026-11-18', 'to' => '2026-11-25'], 'from', 'The from field must be a date after or equal to 2026-11-19.'],
    'more than a month' => [['from' => '2026-11-19', 'to' => '2026-12-31'], 'to', 'Ask for at most 31 days of times at once.'],
]);

test('a time already gone cannot be booked', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();

    $this->actingAs($this->owner)
        ->post(route('owner.meetings.store'), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-18T16:40:00+00:00'])
        ->assertSessionHasErrors(['starts_at' => 'That time has already passed. Please pick another.']);
});

test('the apps read open times and book the same way', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.slots', [$this->account, 'owner-meeting', 'from' => '2026-11-19', 'to' => '2026-11-25']))
        ->assertOk()
        ->assertJsonPath('data.0.startsAt', '2026-11-20T16:40:00+00:00')
        ->assertJsonPath('meta.eventType.slug', 'owner-meeting');

    $this->postJson(route('api.v1.accounts.meetings.store', $this->account), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-20T16:40:00+00:00'])
        ->assertCreated()
        ->assertJsonPath('data.manageUrl', 'https://scheduling.test/bookings/bk-9');
});

test('the apps hear a taken time as a conflict', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling(409);
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.v1.accounts.meetings.store', $this->account), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-20T16:40:00+00:00'])
        ->assertConflict()
        ->assertJsonPath('errors.starts_at.0', 'That time has just been taken.');
});

test('the apps cannot book for a resident account or another client\'s account', function () {
    $this->travelTo('2026-11-19 12:00:00');
    fakeBookableScheduling();
    $resident = ClientAccount::factory()->resident()->for($this->owner)->create();
    $someoneElses = ClientAccount::factory()->owner()->create();
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.v1.accounts.meetings.store', $resident), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-20T16:40:00+00:00'])->assertNotFound();
    $this->postJson(route('api.v1.accounts.meetings.store', $someoneElses), ['event_type' => 'owner-meeting', 'starts_at' => '2026-11-20T16:40:00+00:00'])->assertNotFound();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
