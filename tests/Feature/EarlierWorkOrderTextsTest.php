<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.propertyware.discover_accounts' => false,
        'services.tx_maintenance.url' => 'https://maintenance.test',
        'services.tx_maintenance.token' => 'portal-token',
    ]);

    $this->history = [
        ['work_order' => ['id' => 7, 'propertyware_id' => '8157', 'number' => '1001', 'title' => 'AC not cooling', 'status' => 'Closed'], 'messages' => [
            ['id' => 31, 'from' => 'staff', 'body' => 'The tech comes Tuesday.', 'media' => [], 'sent_at' => '2026-03-02T15:00:00+00:00'],
            ['id' => 32, 'from' => 'client', 'body' => 'Thanks!', 'media' => ['https://maintenance.test/media/5'], 'sent_at' => '2026-03-02T16:00:00+00:00'],
        ]],
        ['work_order' => ['id' => 9, 'propertyware_id' => '8200', 'number' => '1002', 'title' => null, 'status' => 'Closed'], 'messages' => [
            ['id' => 40, 'from' => 'staff', 'body' => 'Fixed.', 'media' => [], 'sent_at' => '2026-05-01T15:00:00+00:00'],
        ]],
    ];

    // For one work order, everyone on that side of it, as tx-maintenance answers.
    $workOrderTexts = [['work_order' => $this->history[0]['work_order'], 'messages' => [
        ['id' => 31, 'from' => 'staff', 'to_you' => true, 'body' => 'The tech comes Tuesday.', 'media' => [], 'sent_at' => '2026-03-02T15:00:00+00:00'],
        ['id' => 33, 'from' => 'other', 'to_you' => false, 'body' => 'I will be home.', 'media' => [], 'sent_at' => '2026-03-02T15:30:00+00:00'],
        ['id' => 34, 'from' => 'staff', 'to_you' => false, 'body' => 'Thanks, see you then.', 'media' => [], 'sent_at' => '2026-03-02T15:40:00+00:00'],
    ]]];

    Http::fake(['maintenance.test/*' => fn (Request $request) => Http::response([
        'data' => isset($request->data()['work_order']) ? ($request['work_order'] === '8157' ? $workOrderTexts : []) : $this->history,
    ])]);

    $this->tenant = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->tenant)->create(['propertyware_id' => '900']);
});

test('lists the earlier work order texts as closed conversations, newest first', function () {
    $this->actingAs($this->tenant);

    $threads = partialGet(route('resident.messages.index'), 'client/Messages', 'earlierTexts')
        ->assertOk()
        ->json('props.earlierTexts');

    expect($threads)->toHaveCount(2)
        ->and($threads[0])->toMatchArray(['id' => -9, 'subject' => 'Work order #1002', 'workOrderId' => '8200', 'isClosed' => true, 'isHistory' => true])
        ->and($threads[1])->toMatchArray(['id' => -7, 'subject' => 'AC not cooling', 'workOrderNumber' => '1001', 'category' => 'maintenance'])
        ->and($threads[1]['lastMessage'])->toMatchArray(['preview' => 'Thanks!', 'fromClient' => true, 'hasAttachment' => true]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer portal-token')
        && str_starts_with($request->url(), 'https://maintenance.test/api/portal/v1/message-history')
        && $request['contact'] === '900'
        && $request['party'] === 'tenant');
});

test('opens an earlier conversation read-only by its negative id', function () {
    $this->actingAs($this->tenant);

    $conversation = partialGet(route('resident.messages.index', ['thread' => -7]), 'client/Messages', 'conversation')
        ->assertOk()
        ->json('props.conversation');

    expect($conversation['thread']['isClosed'])->toBeTrue()
        ->and($conversation['messages'])->toHaveCount(2)
        ->and($conversation['messages'][0])->toMatchArray(['body' => 'The tech comes Tuesday.', 'fromClient' => false, 'author' => EarlierWorkOrderTexts::STAFF])
        ->and($conversation['messages'][1])->toMatchArray(['fromClient' => true, 'author' => null, 'mediaUrls' => ['https://maintenance.test/media/5']]);
});

test('an earlier conversation that is not the client\'s is not found', function () {
    $this->actingAs($this->tenant);

    partialGet(route('resident.messages.index', ['thread' => -99]), 'client/Messages', 'conversation')->assertNotFound();
});

test('asks for an owner\'s side of their work orders, and gives the app every conversation', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $account = ClientAccount::factory()->owner()->for($owner)->create(['propertyware_id' => '500']);

    $this->getJson(route('api.v1.accounts.messages.history', $account))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.thread.id', -7)
        ->assertJsonPath('data.1.messages.0.body', 'The tech comes Tuesday.');

    Http::assertSent(fn (Request $request): bool => $request['contact'] === '500' && $request['party'] === 'owner');
});

test('a work order shows everyone\'s texts on the client\'s side of it', function () {
    $texts = app(EarlierWorkOrderTexts::class);
    $conversation = $texts->forWorkOrder($this->account, '8157');

    expect($conversation['thread']['id'])->toBe(-7)
        ->and(array_column($conversation['messages'], 'author'))->toBe([EarlierWorkOrderTexts::STAFF, 'Another resident', EarlierWorkOrderTexts::STAFF.', to another resident'])
        ->and($texts->forWorkOrder($this->account, '1'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => ($request->data()['work_order'] ?? null) === '8157' && $request['contact'] === '900');
});

test('shows nothing when tx-maintenance cannot answer, and tries again next time', function () {
    config(['services.tx_maintenance.url' => 'https://down.test']);
    Http::fake(['down.test/*' => Http::sequence()->push([], 500)->push(['data' => []])]);
    $texts = app(EarlierWorkOrderTexts::class);

    expect($texts->conversations($this->account))->toBeNull()
        ->and($texts->conversations($this->account))->toBe([]);
});

test('asks nothing when tx-maintenance is not set up', function () {
    config(['services.tx_maintenance.url' => null]);

    expect(app(EarlierWorkOrderTexts::class)->conversations($this->account))->toBe([]);
    Http::assertNothingSent();
});
