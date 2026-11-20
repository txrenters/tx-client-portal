<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

test('reply emails are on until the client turns them off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/Notifications')->where('preferences.message_replies_email', true));

    $this->put(route('notifications.update'), ['message_replies_email' => false])->assertRedirect(route('notifications.edit'));

    expect($user->refresh()->wantsNotification('message_replies_email'))->toBeFalse();
});

test('the app reads and changes notification settings', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // No client account, so nothing to text: "sms" is null.
    $this->getJson(route('api.v1.notification-preferences.show'))
        ->assertExactJson([
            'data' => ['message_replies_email' => true, 'message_replies_push' => true, 'work_order_approvals_push' => true, 'lease_expiry_push' => true, 'work_order_updates_push' => true, 'meeting_reminders_push' => true],
            'meta' => ['sms' => null],
        ]);

    $this->putJson(route('api.v1.notification-preferences.update'), ['message_replies_email' => false])
        ->assertJsonPath('data.message_replies_email', false);
});

test('clients cannot turn texts off, though older apps still send the switch', function () {
    Http::fake();

    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    // An app already installed hides its texts switch when this is null.
    $this->getJson(route('api.v1.notification-preferences.show'))->assertOk()->assertJsonPath('meta.sms', null);

    $this->putJson(route('api.v1.notification-preferences.update'), ['message_replies_sms' => false])
        ->assertOk()
        ->assertJsonPath('meta.sms', null);

    $this->actingAs($user)->put(route('notifications.update'), ['message_replies_sms' => false])->assertRedirect(route('notifications.edit'));

    // Nothing reaches the messaging hub, and nothing about texts is stored here.
    Http::assertNothingSent();
    expect($user->refresh()->notificationPreferences())->toBe(['message_replies_email' => true, 'message_replies_push' => true, 'work_order_approvals_push' => true, 'lease_expiry_push' => true, 'work_order_updates_push' => true, 'meeting_reminders_push' => true]);
});

test('ignores settings that do not exist', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson(route('api.v1.notification-preferences.update'), ['marketing_sms' => true])->assertOk();

    expect($user->refresh()->notificationPreferences())->toBe(['message_replies_email' => true, 'message_replies_push' => true, 'work_order_approvals_push' => true, 'lease_expiry_push' => true, 'work_order_updates_push' => true, 'meeting_reminders_push' => true]);
});
