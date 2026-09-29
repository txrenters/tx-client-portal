<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ChatHub\ChatHubClient;
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
            'data' => ['message_replies_email' => true, 'message_replies_push' => true],
            'meta' => ['sms' => null],
        ]);

    $this->putJson(route('api.v1.notification-preferences.update'), ['message_replies_email' => false])
        ->assertJsonPath('data.message_replies_email', false);
});

test('turns texted replies on through the messaging hub', function () {
    config([
        'services.chat_hub.url' => 'https://support.test/api/v1',
        'services.chat_hub.token' => 'hub-token',
    ]);
    $this->app->forgetInstance(ChatHubClient::class);

    Http::fake([
        'https://support.test/api/v1/contacts/500' => Http::response(['data' => [
            'contact_id' => '500', 'phone' => '+15125550100', 'sms_replies' => false,
        ]]),
    ]);

    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    $this->actingAs($user);

    $this->get(route('notifications.edit'))->assertOk();
    $this->put(route('notifications.update'), ['message_replies_sms' => true])->assertRedirect(route('notifications.edit'));

    // The hub holds it, not us: nothing about texts lands in our own settings.
    expect($user->refresh()->notificationPreferences())->toBe(['message_replies_email' => true, 'message_replies_push' => true]);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://support.test/api/v1/contacts/500'
        && $request['sms_replies'] === true);
});

test('ignores settings that do not exist', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson(route('api.v1.notification-preferences.update'), ['marketing_sms' => true])->assertOk();

    expect($user->refresh()->notificationPreferences())->toBe(['message_replies_email' => true, 'message_replies_push' => true]);
});
