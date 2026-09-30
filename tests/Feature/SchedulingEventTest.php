<?php

use App\Events\MeetingsUpdated;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
    Event::fake([MeetingsUpdated::class]);
    config(['services.scheduling.webhook_secret' => 'scheduling-secret']);

    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    ClientAccount::factory()->owner()->for($this->owner)->create();
});

/**
 * @param  array<string, mixed>  $payload
 */
function bookingEvent(array $payload, string $secret = 'scheduling-secret', int $age = 0, string $delivery = 'delivery-1'): TestResponse
{
    $body = json_encode($payload);
    $timestamp = (string) now()->subSeconds($age)->getTimestamp();

    return test()->call('POST', route('api.v1.scheduling.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BOOKINGS_TIMESTAMP' => $timestamp,
        'HTTP_X_BOOKINGS_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        'HTTP_X_BOOKINGS_DELIVERY' => $delivery,
    ], $body);
}

function bookedBy(string $email): array
{
    return ['event' => 'booking.confirmed', 'booking' => ['uid' => 'abc', 'invitee' => ['email' => $email]]];
}

test('a booking refreshes the owner\'s open meetings page, whatever the address\'s case', function () {
    bookingEvent(bookedBy('Owner@Example.com'))
        ->assertOk()
        ->assertJsonPath('result', 'refreshed');

    Event::assertDispatched(MeetingsUpdated::class, fn (MeetingsUpdated $event) => $event->userId === $this->owner->id);
});

test('a retried delivery is acknowledged but not passed on twice', function () {
    bookingEvent(bookedBy('owner@example.com'))->assertJsonPath('result', 'refreshed');
    bookingEvent(bookedBy('owner@example.com'))->assertOk()->assertJsonPath('result', 'duplicate');

    Event::assertDispatchedTimes(MeetingsUpdated::class, 1);
});

test('a booking by someone who is not an owner here is ignored', function () {
    $resident = User::factory()->create(['email' => 'tenant@example.com']);
    ClientAccount::factory()->resident()->for($resident)->create();

    bookingEvent(bookedBy('tenant@example.com'))->assertOk()->assertJsonPath('result', 'ignored');
    bookingEvent(bookedBy('stranger@example.com'), delivery: 'delivery-2')->assertOk()->assertJsonPath('result', 'ignored');

    Event::assertNotDispatched(MeetingsUpdated::class);
});

test('refuses a booking event that is wrongly signed or stale', function (string $secret, int $age) {
    bookingEvent(bookedBy('owner@example.com'), $secret, $age)->assertUnauthorized();

    Event::assertNotDispatched(MeetingsUpdated::class);
})->with([
    'wrong secret' => ['not-the-secret', 0],
    'replayed later' => ['scheduling-secret', 3600],
]);

test('refuses every booking event until a secret is configured', function () {
    config(['services.scheduling.webhook_secret' => null]);

    bookingEvent(bookedBy('owner@example.com'), '')->assertUnauthorized();
});
