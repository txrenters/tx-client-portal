<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ChatHub\ChatHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.propertyware.discover_accounts' => false,
        'services.chat_hub.url' => 'https://support.test/api/v1',
        'services.chat_hub.token' => 'hub-token',
    ]);
    $this->app->forgetInstance(ChatHubClient::class);

    $thread = fn (int $id, ?array $last): array => [
        'id' => $id, 'status' => 'open', 'subject' => "Thread {$id}", 'contact_id' => '500', 'phone' => null,
        'work_order_id' => null, 'work_order_number' => null, 'updated_at' => '2026-09-18T10:00:00+00:00', 'last_message' => $last,
    ];

    Http::fake(['support.test/api/v1/threads*' => Http::response([
        'data' => [
            $thread(10, ['id' => 2, 'sender' => 'user', 'body' => 'Hello', 'has_attachment' => false, 'created_at' => '2026-09-18T10:05:00+00:00']),
            $thread(11, null),
            $thread(12, null),
        ],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 3],
    ])]);

    $this->owner = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create(['propertyware_id' => '500']);
});

test('leaves conversations without a single message out of the Messages list', function () {
    $this->actingAs($this->owner);

    $threads = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')
        ->assertOk()
        ->json('props.threads.data');

    expect(array_column($threads, 'id'))->toBe([10]);
});

test('leaves them out of the app\'s list too', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.messages.index', $this->account))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 10);
});

test('reads on past a page of nothing but empty conversations, and the list carries on from there', function () {
    $empty = fn (int $id): array => ['id' => $id, 'status' => 'open', 'subject' => 'Conversation with TexasRenters.com', 'contact_id' => '600', 'phone' => null, 'work_order_id' => null, 'work_order_number' => null, 'updated_at' => '2026-02-27T10:00:00+00:00', 'last_message' => null];
    $real = ['id' => 99, 'status' => 'open', 'subject' => 'Real one', 'contact_id' => '600', 'phone' => null, 'work_order_id' => null, 'work_order_number' => null, 'updated_at' => '2026-02-01T10:00:00+00:00', 'last_message' => ['id' => 5, 'sender' => 'user', 'body' => 'Hi', 'has_attachment' => false, 'created_at' => '2026-02-01T10:00:00+00:00']];
    $this->app->forgetInstance(ChatHubClient::class);
    config(['services.chat_hub.url' => 'https://busy.test/api/v1']);
    Http::fake(['busy.test/api/v1/threads*' => fn (Request $request) => Http::response(match ((int) ($request->data()['page'] ?? 1)) {
        1 => ['data' => array_map($empty, range(1, 25)), 'meta' => ['current_page' => 1, 'last_page' => 3, 'total' => 60]],
        2 => ['data' => [...array_map($empty, range(26, 49)), $real], 'meta' => ['current_page' => 2, 'last_page' => 3, 'total' => 60]],
        default => ['data' => array_map($empty, range(51, 60)), 'meta' => ['current_page' => 3, 'last_page' => 3, 'total' => 60]],
    })]);
    $busy = User::factory()->create();
    ClientAccount::factory()->owner()->for($busy)->create(['propertyware_id' => '600']);
    $this->actingAs($busy);

    $threads = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')->assertOk()->json('props.threads');

    expect(array_column($threads['data'], 'id'))->toBe([99]);
    expect(json_encode($threads))->toContain('page=3');
});
