<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false, 'services.chat_hub.url' => null]);
    Http::fake(['*' => Http::response([])]);
    $this->user = User::factory()->create();
    $this->tenant = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '900']);
    $this->user->notify(new LeaseExpiringNotification([
        'type' => 'lease_expiring', 'role' => 'resident', 'account_id' => $this->tenant->id, 'lease_id' => 55, 'building_id' => 7,
        'title' => 'Your lease ends Dec 30, 2026', 'body' => 'b', 'message' => 'm', 'url' => route('resident.lease'),
    ]));
});

test('the bell counts and lists an unread lease notice', function () {
    $this->actingAs($this->user)->getJson(route('notifications.latest'))
        ->assertOk()
        ->assertJsonPath('notices.count', 1)
        ->assertJsonPath('notices.items.0.title', 'Your lease ends Dec 30, 2026')
        ->assertJsonPath('count', 1);
});

test('opening a notice marks it read and goes where it points', function () {
    $id = $this->user->notifications()->sole()->id;

    $this->actingAs($this->user)->post(route('notifications.read', $id))
        ->assertRedirect(route('resident.lease'));

    expect($this->user->notifications()->sole()->read_at)->not->toBeNull();
    $this->actingAs($this->user)->getJson(route('notifications.latest'))->assertJsonPath('notices.count', 0);
});

test('a notice pointing at another host goes to the notifications page instead', function () {
    $this->user->notify(new LeaseExpiringNotification([
        'type' => 'lease_expiring', 'role' => 'resident', 'account_id' => $this->tenant->id, 'lease_id' => 56, 'building_id' => 7,
        'title' => 't', 'body' => 'b', 'message' => 'm', 'url' => 'https://evil.example/phish?x=1',
    ]));
    $id = $this->user->notifications()->get()->first(fn ($notice) => $notice->data['lease_id'] === 56)->id;

    $this->actingAs($this->user)->post(route('notifications.read', $id))
        ->assertRedirect(route('notifications.index'));
});

test('another login cannot open someone elses notice', function () {
    $id = $this->user->notifications()->sole()->id;
    $other = User::factory()->create();
    ClientAccount::factory()->resident()->for($other)->create(['propertyware_id' => '901']);

    $this->actingAs($other)->post(route('notifications.read', $id))->assertNotFound();

    expect($this->user->notifications()->sole()->read_at)->toBeNull();
});

test('a malformed notification id is not found on the web', function () {
    $this->actingAs($this->user)->post(route('notifications.read', 'not-a-uuid'))->assertNotFound();
});

test('a malformed notification id is not found in the app', function () {
    Sanctum::actingAs($this->user);

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, 'not-a-uuid']))->assertNotFound();
});
