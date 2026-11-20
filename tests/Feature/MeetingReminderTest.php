<?php

use App\Models\ClientAccount;
use App\Models\MeetingReminder;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\MeetingReminderNotification;
use App\Services\Notifications\NeedsAttention;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'services.scheduling.url' => 'https://scheduling.test',
        'services.scheduling.token' => 'secret',
        'services.scheduling.page' => 'texas-renters',
    ]);

    // 10:00 in Houston.
    $this->travelTo('2026-11-21 16:00:00');

    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->first = ClientAccount::factory()->owner()->for($this->owner)->create();
    $this->second = ClientAccount::factory()->owner()->for($this->owner)->create();
});

/**
 * Coresched's bookings for the next hour.
 *
 * @param  array<int, array<string, mixed>>  $bookings
 */
function bookingsInTheNextHour(array $bookings): void
{
    Http::fake(['*/organizations/texas-renters/bookings*' => Http::response(['data' => array_map(fn (array $booking): array => [
        'uid' => 'bk-1',
        'status' => 'confirmed',
        'eventName' => 'Accounting Team Meeting - Phone Call',
        'invitee' => ['name' => 'Owner', 'email' => 'Owner@Example.com'],
        'canceledAt' => null,
        ...$booking,
    ], $bookings)])]);
}

test('an owner is reminded an hour before, then five minutes before, once each', function () {
    Notification::fake();
    bookingsInTheNextHour([['startsAt' => '2026-11-21T17:00:00+00:00']]);

    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 1);
    Notification::assertSentTo($this->owner, fn (MeetingReminderNotification $notification): bool => $notification->notice['title'] === 'Your meeting starts in 1 hour'
        && $notification->notice['body'] === 'Accounting Team Meeting - Phone Call at 11:00 AM'
        && $notification->notice['account_id'] === [$this->first->id, $this->second->id]
        && $notification->notice['url'] === route('owner.meetings.index'));

    // 55 minutes later: five minutes to go.
    $this->travel(55)->minutes();
    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 2);
    Notification::assertSentTo($this->owner, fn (MeetingReminderNotification $notification): bool => $notification->notice['title'] === 'Your meeting starts in 5 minutes'
        && $notification->notice['reminder'] === MeetingReminder::FIVE_MINUTES);
});

test('a meeting booked with under an hour to go says how long is left', function () {
    Notification::fake();
    bookingsInTheNextHour([['startsAt' => '2026-11-21T16:40:00+00:00']]);

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentTo($this->owner, fn (MeetingReminderNotification $notification): bool => $notification->notice['title'] === 'Your meeting starts in 40 minutes');
});

test('canceled and moved bookings, strangers, unverified and demo logins are not reminded', function () {
    Notification::fake();
    User::factory()->unverified()->create(['email' => 'unverified@example.com'])
        ->clientAccounts()->save(ClientAccount::factory()->owner()->make());
    User::factory()->create(['email' => 'demo@example.com', 'is_demo' => true])
        ->clientAccounts()->save(ClientAccount::factory()->owner()->make());

    bookingsInTheNextHour([
        ['uid' => 'bk-canceled', 'status' => 'canceled', 'canceledAt' => '2026-11-21T15:00:00+00:00', 'startsAt' => '2026-11-21T16:30:00+00:00'],
        ['uid' => 'bk-moved', 'status' => 'rescheduled', 'startsAt' => '2026-11-21T16:30:00+00:00'],
        ['uid' => 'bk-stranger', 'invitee' => ['email' => 'nobody@example.com'], 'startsAt' => '2026-11-21T16:30:00+00:00'],
        ['uid' => 'bk-demo', 'invitee' => ['email' => 'demo@example.com'], 'startsAt' => '2026-11-21T16:30:00+00:00'],
        ['uid' => 'bk-unverified', 'invitee' => ['email' => 'unverified@example.com'], 'startsAt' => '2026-11-21T16:30:00+00:00'],
    ]);

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

test('a meeting moved to a new time is reminded about again', function () {
    Notification::fake();
    $booking = fn (string $startsAt): array => ['data' => [[
        'uid' => 'bk-1', 'status' => 'confirmed', 'eventName' => 'Owner meeting', 'canceledAt' => null,
        'invitee' => ['email' => 'owner@example.com'], 'startsAt' => $startsAt,
    ]]];
    Http::fake(['*/bookings*' => Http::sequence()
        ->push($booking('2026-11-21T17:00:00+00:00'))
        ->push($booking('2026-11-21T16:50:00+00:00'))]);

    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MeetingReminderNotification::class, 2);
});

test('a coresched outage sends nothing and records nothing, to try again next minute', function () {
    Notification::fake();
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertNothingSent();
    expect(MeetingReminder::count())->toBe(0);
});

test('the reminder shows under each of the owner\'s accounts, in the portal and the app', function () {
    bookingsInTheNextHour([['startsAt' => '2026-11-21T17:00:00+00:00']]);
    $this->artisan('meetings:remind')->assertSuccessful();

    $attention = app(NeedsAttention::class);
    expect($attention->notices($this->owner, $this->first)->count())->toBe(1)
        ->and($attention->notices($this->owner, $this->second)->count())->toBe(1);

    // As both clients read it.
    expect(NeedsAttention::present($attention->notices($this->owner, $this->second)->sole()))
        ->toMatchArray([
            'type' => 'meeting_reminder',
            'role' => 'owner',
            'title' => 'Your meeting starts in 1 hour',
            'body' => 'Accounting Team Meeting - Phone Call at 11:00 AM',
            'url' => route('owner.meetings.index'),
        ]);
});

test('the push follows the meeting reminders setting', function () {
    $notification = new MeetingReminderNotification([1], 'bk-1', 'Owner meeting', now()->toImmutable()->addHour(), MeetingReminder::HOUR);

    expect($notification->via($this->owner))->toContain(ExpoChannel::class);

    $this->owner->forceFill(['notification_preferences' => ['meeting_reminders_push' => false]])->save();

    expect($notification->via($this->owner->refresh()))->toBe(['database']);
});
