<?php

use App\Models\PushToken;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function leaseNotice(array $overrides = []): array
{
    return ['type' => 'lease_expiring', 'role' => 'resident', 'account_id' => 42, 'lease_id' => 55, 'building_id' => 7,
        'title' => 'Your lease ends Dec 30, 2026', 'body' => 'To renew…', 'message' => 'Dear…', 'url' => 'https://portal.test/resident/lease', ...$overrides];
}

test('is kept in the list and pushed to every phone', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]])]);
    $user = User::factory()->create();
    PushToken::factory()->for($user)->create(['token' => 'ExponentPushToken[one]']);

    $user->notify(new LeaseExpiringNotification(leaseNotice()));

    $stored = $user->notifications()->sole();
    expect($stored->data)->toMatchArray(['type' => 'lease_expiring', 'account_id' => 42, 'title' => 'Your lease ends Dec 30, 2026']);

    Http::assertSent(function (Request $request) use ($stored) {
        $push = $request->data()[0];

        return $push['title'] === 'Your lease ends Dec 30, 2026'
            && $push['data'] === ['type' => 'lease_expiring', 'accountId' => 42, 'leaseId' => 55, 'buildingId' => 7, 'notificationId' => $stored->id, 'url' => 'https://portal.test/resident/lease'];
    });
});

test('with lease reminders switched off the phone stays quiet but the list still has it', function () {
    Http::fake();
    $user = User::factory()->create(['notification_preferences' => ['lease_expiry_push' => false]]);
    PushToken::factory()->for($user)->create();

    $user->notify(new LeaseExpiringNotification(leaseNotice()));

    expect($user->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});
