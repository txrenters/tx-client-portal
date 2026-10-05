<?php

use App\Models\LeaseExpiryNotice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

test('lease reminders are pushed by default and can be turned off', function () {
    $user = User::factory()->create();
    expect($user->wantsNotification('lease_expiry_push'))->toBeTrue();

    Sanctum::actingAs($user);
    $this->putJson(route('api.v1.notification-preferences.update'), ['lease_expiry_push' => false])
        ->assertOk()
        ->assertJsonPath('data.lease_expiry_push', false);

    expect($user->fresh()->wantsNotification('lease_expiry_push'))->toBeFalse();
});

test('a lease and end date is recorded only once', function () {
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2027-01-05', 'notified_at' => now(), 'recipients' => 2]);

    expect(fn () => LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2027-01-05', 'recipients' => 0]))
        ->toThrow(UniqueConstraintViolationException::class);

    // An extension is a new end date, so it can be recorded again.
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2028-01-05', 'recipients' => 0]);
    expect(LeaseExpiryNotice::count())->toBe(2);
});

test('stored notices older than 90 days are pruned', function () {
    $user = User::factory()->create();
    foreach ([91, 89] as $age) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => [],
            'created_at' => now()->subDays($age),
        ]);
    }

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(DatabaseNotification::count())->toBe(1);
});
