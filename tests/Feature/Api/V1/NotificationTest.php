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
    Sanctum::actingAs($this->user);
    $this->tenant = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '900']);
    $this->owner = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
});

function noticeFor(ClientAccount $account, string $title): void
{
    $account->user->notify(new LeaseExpiringNotification([
        'type' => 'lease_expiring', 'role' => $account->role->value, 'account_id' => $account->id, 'lease_id' => 55, 'building_id' => 7,
        'building' => '7734 Park Falls', 'unit' => '7734 Park Falls', 'start_date' => '2024-12-27', 'end_date' => '2026-12-30',
        'renewal_deadline' => '2026-10-31', 'days_left' => 90, 'title' => $title, 'body' => 'b', 'message' => 'm', 'url' => 'https://x',
    ]));
}

test('lists only the notices for the account asked about, with an unread count', function () {
    noticeFor($this->tenant, 'Tenant notice');
    noticeFor($this->owner, 'Owner notice');

    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Tenant notice')
        ->assertJsonPath('data.0.type', 'lease_expiring')
        ->assertJsonPath('data.0.endDate', '2026-12-30')
        ->assertJsonPath('data.0.readAt', null)
        ->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('meta.messages', ['count' => 0, 'items' => []])
        ->assertJsonPath('meta.approvals', ['count' => 0, 'items' => []]);

    $this->getJson(route('api.v1.accounts.notifications.index', $this->owner))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Owner notice')
        ->assertJsonPath('data.0.role', 'owner');
});

test('reads one notice, then all of them', function () {
    noticeFor($this->tenant, 'One');
    noticeFor($this->tenant, 'Two');
    $id = $this->user->notifications()->latest()->first()->id;

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, $id]))->assertNoContent();
    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertJsonPath('meta.unread', 1);

    $this->postJson(route('api.v1.accounts.notifications.read-all', $this->tenant))->assertNoContent();
    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertJsonPath('meta.unread', 0);
});

test('reading all of one account leaves the other account unread', function () {
    noticeFor($this->tenant, 'Tenant notice');
    noticeFor($this->owner, 'Owner notice');

    $this->postJson(route('api.v1.accounts.notifications.read-all', $this->tenant))->assertNoContent();

    $this->getJson(route('api.v1.accounts.notifications.index', $this->owner))
        ->assertJsonPath('data.0.readAt', null)
        ->assertJsonPath('meta.unread', 1);
});

test('a notice from another account cannot be read through this one', function () {
    noticeFor($this->owner, 'Owner notice');
    $id = $this->user->notifications()->sole()->id;

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, $id]))->assertNotFound();

    expect($this->user->notifications()->sole()->read_at)->toBeNull();
});

test("another user's notice cannot be read through this account", function () {
    $stranger = ClientAccount::factory()->resident()->create(['propertyware_id' => '901']);
    noticeFor($stranger, 'Stranger notice');
    $id = $stranger->user->notifications()->sole()->id;

    $this->postJson(route('api.v1.accounts.notifications.read', [$this->tenant, $id]))->assertNotFound();

    expect($stranger->user->notifications()->sole()->read_at)->toBeNull();
});

test('another user cannot list this account', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.accounts.notifications.index', $this->tenant))->assertNotFound();
});
