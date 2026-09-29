<?php

use App\Models\AdminAction;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Scheduling\MeetingCatalog;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.scheduling.url' => 'https://scheduling.test',
        'services.scheduling.token' => 'token',
        'services.scheduling.page' => 'texas-renters',
        'services.admin_emails' => ['staff@texasrenters.com'],
    ]);

    $this->owner = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create();

    // Two of the approved phone calls, a screen share, and something a
    // colleague made in scheduling that has nothing to do with clients.
    Http::fake([
        '*/event-types' => Http::response(['data' => [
            ['slug' => 'accounting-team-meeting', 'name' => 'Accounting Team Meeting - Phone Call'],
            ['slug' => 'leasing-team-meeting-phone-call', 'name' => 'Leasing Team Meeting - Phone Call'],
            ['slug' => 'leasing-team-meeting-virtual-via-microsoft-teams', 'name' => 'Leasing Team Meeting - Screen Share'],
            ['slug' => 'staff-lunch', 'name' => 'Staff lunch'],
        ]]),
        '*/bookings*' => Http::response(['data' => []]),
    ]);
});

test('owners only see the approved meeting types, starting with the four phone calls', function () {
    $this->actingAs($this->owner)->get(route('owner.meetings.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('eventTypes', 2)
                ->where('eventTypes.0.slug', 'accounting-team-meeting')
                ->where('eventTypes.1.slug', 'leasing-team-meeting-phone-call')));
});

test('the app only gets the approved meeting types too', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.meetings.index', $this->account))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['slug' => 'staff-lunch']);
});

test('staff switch a meeting type on, and unticking everything shows none rather than all', function () {
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    $this->actingAs($admin)
        ->put(route('admin.meetings.update'), ['slugs' => ['leasing-team-meeting-virtual-via-microsoft-teams']])
        ->assertRedirect();

    expect(app(MeetingCatalog::class)->forClients())->toHaveCount(1)
        ->and(AdminAction::sole()->action)->toBe('meeting_types_changed');

    $this->actingAs($admin)->put(route('admin.meetings.update'), ['slugs' => []])->assertRedirect();

    expect(app(MeetingCatalog::class)->forClients())->toBe([]);
});

test('the admin page lists every scheduling type with whether clients see it', function () {
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    $this->actingAs($admin)->get(route('admin.meetings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Meetings')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('meetingTypes.types', 4)
                ->where('meetingTypes.types.3.slug', 'staff-lunch')
                ->where('meetingTypes.types.3.allowed', false)));
});

test('clients cannot change which meetings are offered', function () {
    $this->actingAs($this->owner)->put(route('admin.meetings.update'), ['slugs' => ['staff-lunch']])->assertForbidden();

    expect(app(MeetingCatalog::class)->allowedSlugs())->toBe(MeetingCatalog::DEFAULT_SLUGS);
});
