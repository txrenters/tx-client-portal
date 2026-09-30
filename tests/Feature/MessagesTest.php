<?php

use App\Enums\ClientRole;
use App\Events\ClientConversationUpdated;
use App\Models\ClientAccount;
use App\Models\ConversationRead;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\StaffRepliedNotification;
use App\Services\ChatHub\ChatHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.propertyware.discover_accounts' => false,
        'services.chat_hub.url' => 'https://support.test/api/v1',
        'services.chat_hub.token' => 'hub-token',
        'services.chat_hub.webhook_secret' => 'hub-secret',
    ]);
    $this->app->forgetInstance(ChatHubClient::class);

    $thread = fn (int $id, string $contactId, array $extra = []): array => [
        'id' => $id, 'status' => 'escalated', 'subject' => "Thread {$id}", 'contact_id' => $contactId, 'phone' => '+15125550100',
        'work_order_id' => null, 'work_order_number' => null, 'updated_at' => '2026-09-18T10:00:00+00:00', ...$extra,
    ];

    Http::fake(function (Request $request) use ($thread) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            // Propertyware, for the owner's properties in the new-message picker.
            $path === '/pw/api/rest/v1/contacts/500' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
            $path === '/pw/api/rest/v1/buildings' => Http::response([
                ['id' => 7, 'portfolioID' => 2, 'name' => 'Montebello Manor', 'address' => ['address' => '12414 Montebello Manor Ln']],
                ['id' => 8, 'portfolioID' => 2, 'name' => 'Ranch Road', 'address' => ['address' => '900 Ranch Rd']],
            ]),
            $path === '/pw/api/rest/v1/units' => Http::response([]),
            $request->method() === 'GET' && $path === '/api/v1/threads' => Http::response([
                'data' => [$thread(10, '500', ['type' => 'accounting', 'work_order_id' => '8157', 'work_order_number' => '1001', 'subject' => 'Work order #1001', 'assigned_to' => 'Laura Motelet', 'assigned_to_photo_url' => 'https://support.test/photos/laura.jpg', 'last_message' => [
                    'id' => 2, 'sender' => 'agent', 'sender_name' => 'Laura Motelet', 'direction' => 'outbound', 'body' => 'See you Tuesday', 'has_attachment' => false, 'created_at' => '2026-09-18T10:05:00+00:00',
                ]])],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
            ]),
            $request->method() === 'GET' && $path === '/api/v1/threads/10' => Http::response(['data' => $thread(10, '500')]),
            $request->method() === 'GET' && $path === '/api/v1/threads/11' => Http::response(['data' => $thread(11, '999')]),
            $request->method() === 'GET' && $path === '/api/v1/threads/10/messages' => Http::response(['data' => [
                ['id' => 1, 'direction' => 'outbound', 'sender' => 'agent', 'sender_name' => 'Laura Motelet', 'sender_photo_url' => 'https://support.test/photos/laura.jpg', 'body' => 'The plumber comes Tuesday.', 'media_urls' => [], 'sender_number' => '+15125550199', 'created_at' => '2026-09-18T10:00:00+00:00'],
                ['id' => 2, 'direction' => 'inbound', 'sender' => 'user', 'sender_name' => null, 'body' => 'Thanks!', 'media_urls' => [], 'sender_number' => null, 'created_at' => '2026-09-18T10:05:00+00:00'],
            ]]),
            // As the hub does: the subject the app sent, or one named after the category.
            $request->method() === 'POST' && $path === '/api/v1/threads' => Http::response(['data' => $thread(12, '500', [
                'subject' => $request->data()['subject'] ?? ucfirst((string) ($request->data()['category'] ?? '')).' question',
            ])], 201),
            $request->method() === 'POST' && str_ends_with($path, '/messages') => Http::response(['data' => ['id' => 3]], 201),
            default => Http::response([], 404),
        };
    });

    $this->owner = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create(['propertyware_id' => '500']);
});

test('shows the owner only their own conversations, without phone numbers', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')->assertOk();

    expect($response->json('props.threads.data.0'))->toMatchArray(['id' => 10, 'kind' => 'maintenance', 'workOrderNumber' => '1001'])
        ->and($response->json('props.threads.data.0'))->not->toHaveKey('phone')
        ->and($response->json('scrollProps.threads.nextPage'))->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads?contact_id=500&page=1&sort=open_first'
        && $request->hasHeader('Authorization', 'Bearer hub-token'));
});

test('labels each conversation with the category the client picked', function () {
    $this->actingAs($this->owner);

    $threads = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')->assertOk();

    expect($threads->json('props.threads.data.0.category'))->toBe('accounting');
});

test('shows which number a text used, and nothing on messages written in the app', function () {
    $this->actingAs($this->owner);

    $conversation = partialGet(route('owner.messages.index', ['thread' => 10]), 'client/Messages', 'conversation')->assertOk();

    expect($conversation->json('props.conversation.messages.0.number'))->toBe('(512) 555-0199')
        ->and($conversation->json('props.conversation.messages.1.number'))->toBeNull();
});

test('shows the client the number we text them at, and no one else\'s', function () {
    $this->actingAs($this->owner);

    $conversation = partialGet(route('owner.messages.index', ['thread' => 10]), 'client/Messages', 'conversation')->assertOk();

    expect($conversation->json('props.conversation.thread.phone'))->toBe('(512) 555-0100');

    // The list still carries no numbers at all.
    $threads = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')->assertOk();

    expect($threads->json('props.threads.data.0'))->not->toHaveKey('phone');
});

test('shows a conversation with staff and client messages told apart', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.messages.index', ['thread' => 10]), 'client/Messages', 'conversation')->assertOk();

    expect($response->json('props.conversation.messages'))->toMatchArray([
        ['id' => 1, 'body' => 'The plumber comes Tuesday.', 'mediaUrls' => [], 'fromClient' => false, 'author' => 'Laura Motelet', 'authorPhotoUrl' => 'https://support.test/photos/laura.jpg', 'number' => '(512) 555-0199', 'at' => '2026-09-18T10:00:00+00:00'],
        ['id' => 2, 'body' => 'Thanks!', 'mediaUrls' => [], 'fromClient' => true, 'author' => null, 'authorPhotoUrl' => null, 'number' => null, 'at' => '2026-09-18T10:05:00+00:00'],
    ]);
});

test('names the staff member handling each conversation, for the list and the apps', function () {
    $this->actingAs($this->owner);

    $page = partialGet(route('owner.messages.index'), 'client/Messages', 'threads')->assertOk();

    expect($page->json('props.threads.data.0.assignedStaff'))
        ->toBe(['name' => 'Laura Motelet', 'photoUrl' => 'https://support.test/photos/laura.jpg']);

    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.messages.index', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.assignedStaff.name', 'Laura Motelet')
        ->assertJsonPath('data.0.assignedStaff.photoUrl', 'https://support.test/photos/laura.jpg');
});

test('leaves the staff member empty while no one has picked the conversation up', function () {
    $this->actingAs($this->owner);

    // The hub sends no assigned_to for this thread: nobody has taken it yet.
    $conversation = partialGet(route('owner.messages.index', ['thread' => 10]), 'client/Messages', 'conversation')->assertOk();

    expect($conversation->json('props.conversation.thread.assignedStaff'))->toBeNull();
});

test('never opens or writes in another client\'s conversation', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.messages.show', [$this->account, 11]))->assertNotFound();
    $this->postJson(route('api.v1.accounts.messages.reply', [$this->account, 11]), ['body' => 'Hello'])->assertNotFound();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('sends a reply as the client, once, however often it is retried', function () {
    $key = (string) Str::uuid();
    $this->actingAs($this->owner)
        ->post(route('owner.messages.reply', 10), ['body' => 'Any update?', 'idempotency_key' => $key])
        ->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://support.test/api/v1/threads/10/messages'
        && $request['from'] === 'client'
        && $request['body'] === 'Any update?'
        && $request['idempotency_key'] === $key);
});

test('a client\'s message refreshes their other open pages and devices straight away', function () {
    Event::fake([ClientConversationUpdated::class]);
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.v1.accounts.messages.reply', [$this->account, 10]), ['body' => 'Sent from my phone'])
        ->assertSuccessful();

    Event::assertDispatched(ClientConversationUpdated::class, fn (ClientConversationUpdated $event): bool => $event->contactId === '500' && $event->threadId === 10);
});

test('starts a conversation on the category the client picked and opens it', function () {
    $this->actingAs($this->owner)
        ->post(route('owner.messages.store'), ['category' => 'accounting', 'body' => 'Why is this fee here?'])
        ->assertRedirect(route('owner.messages.index', ['thread' => 12]));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads'
        && $request['contact_id'] === '500'
        && $request['category'] === 'accounting'
        && ! array_key_exists('subject', $request->data()));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads/12/messages' && $request['from'] === 'client');
});

test('asks a multi-property owner which property, and sends it with the message', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.messages.index'), 'client/Messages', 'properties')->assertOk();

    expect($response->json('props.properties'))->toMatchArray([
        ['id' => 7, 'label' => 'Montebello Manor'],
        ['id' => 8, 'label' => 'Ranch Road'],
    ]);

    $this->post(route('owner.messages.store'), ['category' => 'accounting', 'property_id' => 8, 'body' => 'Why is this fee here?'])
        ->assertRedirect(route('owner.messages.index', ['thread' => 12]));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads'
        && $request['building_id'] === '8');
});

test('never asks a tenant which property', function () {
    $resident = User::factory()->create();
    ClientAccount::factory()->resident()->for($resident)->create(['propertyware_id' => '900']);

    $this->actingAs($resident);

    $response = partialGet(route('resident.messages.index'), 'client/Messages', 'properties')->assertOk();

    expect($response->json('props.properties'))->toBe([]);
});

test('asks for a category the hub routes, and nothing else', function () {
    $this->actingAs($this->owner)
        ->post(route('owner.messages.store'), ['body' => 'Why is this fee here?'])
        ->assertSessionHasErrors('category');

    $this->actingAs($this->owner)
        ->post(route('owner.messages.store'), ['category' => 'review', 'body' => 'Why is this fee here?'])
        ->assertSessionHasErrors('category');

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads');
});

test('still starts a conversation from a shipped app version that sends its own subject', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.v1.accounts.messages.store', $this->account), ['subject' => 'Statement question', 'body' => 'Why is this fee here?'])
        ->assertCreated()
        ->assertJsonPath('data.id', 12);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads'
        && $request['subject'] === 'Statement question'
        && ! array_key_exists('category', $request->data()));
});

test('the app can start a conversation on a category too', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.v1.accounts.messages.store', $this->account), ['category' => 'maintenance', 'body' => 'Is my work order scheduled?'])
        ->assertCreated();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://support.test/api/v1/threads' && $request['category'] === 'maintenance');
});

test('shows the page without messaging when the hub is not set up', function () {
    config(['services.chat_hub.token' => null]);
    $this->app->forgetInstance(ChatHubClient::class);

    $this->actingAs($this->owner)->get(route('owner.messages.index'))
        ->assertInertia(fn ($page) => $page->component('client/Messages')->where('available', false));
});

test('the app lists conversations with the channel to listen on', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.messages.index', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.id', 10)
        ->assertJsonPath('meta.channel', 'contacts.500.messages');
});

test('tells the app where to listen live, reachable from the phone', function () {
    config([
        'broadcasting.connections.reverb.key' => 'app-key',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);
    Sanctum::actingAs($this->owner);

    // A local Reverb named "localhost" is reached through the address the phone used for the portal.
    $this->getJson('http://192.168.1.20:8000'.route('api.v1.accounts.realtime', $this->account, false))
        ->assertOk()
        ->assertExactJson(['data' => [
            'key' => 'app-key',
            'host' => '192.168.1.20',
            'port' => 8080,
            'tls' => false,
            'channel' => 'contacts.500.messages',
            'event' => 'conversation.updated',
            'authEndpoint' => 'http://192.168.1.20:8000/api/v1/broadcasting/auth',
            'meetings' => [
                'channel' => "App.Models.User.{$this->owner->id}",
                'event' => 'meetings.updated',
            ],
        ]]);
});

test('passes on a signed hub event to the client\'s open page', function () {
    Event::fake([ClientConversationUpdated::class]);
    $body = json_encode(['event' => 'message.sent', 'data' => ['thread' => ['id' => 10, 'contact_id' => '500']]]);
    $timestamp = (string) now()->getTimestamp();

    $this->call('POST', route('api.v1.hub.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_TIMESTAMP' => $timestamp,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'hub-secret'),
    ], $body)->assertNoContent();

    Event::assertDispatched(ClientConversationUpdated::class, fn ($event) => $event->contactId === '500' && $event->threadId === 10);
});

test('refuses a hub event that is unsigned, wrongly signed or stale', function (string $secret, int $age) {
    Event::fake([ClientConversationUpdated::class]);
    $body = json_encode(['data' => ['thread' => ['id' => 10, 'contact_id' => '500']]]);
    $timestamp = (string) now()->subSeconds($age)->getTimestamp();

    $this->call('POST', route('api.v1.hub.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_TIMESTAMP' => $timestamp,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], $body)->assertUnauthorized();

    Event::assertNotDispatched(ClientConversationUpdated::class);
})->with([
    'wrong secret' => ['not-the-secret', 0],
    'replayed later' => ['hub-secret', 3600],
]);

test('sends each of the client\'s files as its own message, the first with the text', function () {
    $this->actingAs($this->owner)
        ->post(route('owner.messages.reply', 10), [
            'body' => 'Here are the photos',
            'idempotency_key' => '6f1c1f59-2a51-4a0e-9d36-0c1f1b1f1a11',
            'attachments' => [
                UploadedFile::fake()->image('leak.jpg'),
                UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $parts = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'POST' && $request->isMultipart())
        ->map(fn (Request $request) => collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['filename'] ?? $part['contents']]))
        ->values();

    expect($parts)->toHaveCount(2)
        ->and($parts[0]->all())->toMatchArray(['body' => 'Here are the photos', 'attachment' => 'leak.jpg', 'idempotency_key' => '6f1c1f59-2a51-4a0e-9d36-0c1f1b1f1a11'])
        ->and($parts[1]->all())->toMatchArray(['attachment' => 'invoice.pdf', 'idempotency_key' => '6f1c1f59-2a51-4a0e-9d36-0c1f1b1f1a11:1'])
        ->and($parts[1]->has('body'))->toBeFalse();
});

test('allows at most five files in one message', function () {
    $this->actingAs($this->owner)
        ->post(route('owner.messages.reply', 10), ['attachments' => array_fill(0, 6, UploadedFile::fake()->image('p.jpg'))])
        ->assertSessionHasErrors('attachments');
});

test('narrows conversations to a date range', function () {
    $this->actingAs($this->owner);

    partialGet(route('owner.messages.index', ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']), 'client/Messages', 'threads', 'filters')
        ->assertOk()
        ->assertJsonPath('props.filters.range', 'custom');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://support.test/api/v1/threads?')
        && $request['from'] === '2026-09-01' && $request['to'] === '2026-09-30');
});

test('refuses a date range that ends before it starts', function () {
    $this->actingAs($this->owner)
        ->get(route('owner.messages.index', ['from' => '2026-09-30', 'to' => '2026-09-01']))
        ->assertSessionHasErrors('to');
});

test('shows only open or closed conversations', function () {
    $this->actingAs($this->owner);

    partialGet(route('owner.messages.index', ['status' => 'open']), 'client/Messages', 'threads')->assertOk();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://support.test/api/v1/threads?') && $request['status'] === 'active');
});

function hubEvent(array $payload): TestResponse
{
    $body = json_encode($payload);
    $timestamp = (string) now()->getTimestamp();

    return test()->call('POST', route('api.v1.hub.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_TIMESTAMP' => $timestamp,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'hub-secret'),
    ], $body);
}

function staffReply(string $body): array
{
    return ['event' => 'message.sent', 'data' => [
        'thread' => ['id' => 10, 'contact_id' => '500', 'subject' => 'Kitchen leak'],
        'message' => ['sender' => 'agent', 'sender_name' => 'Laura Motelet', 'channel' => 'app', 'body' => $body],
    ]];
}

test('pushes every reply to the app but emails at most one per conversation', function () {
    Notification::fake();

    hubEvent(staffReply('The plumber comes Tuesday.'))->assertNoContent();
    hubEvent(staffReply('Also, please leave the gate open.'))->assertNoContent();

    Notification::assertSentToTimes($this->owner, StaffRepliedNotification::class, 2);

    $sent = collect();
    Notification::assertSentTo($this->owner, StaffRepliedNotification::class, function ($notification) use ($sent) {
        $sent->push($notification->channels);

        return $notification->threadId === 10 && $notification->author === 'Laura Motelet' && $notification->role === ClientRole::Owner;
    });

    expect($sent)->toHaveCount(2)
        ->and($sent->flatten()->filter(fn (string $channel): bool => $channel === 'mail'))->toHaveCount(1)
        ->and($sent->every(fn (array $each): bool => in_array(ExpoChannel::class, $each, true)))->toBeTrue();
});

test('a pushed reply carries what the app needs to open the conversation', function () {
    Notification::fake();

    hubEvent(staffReply('The plumber comes Tuesday.'))->assertNoContent();

    Notification::assertSentTo($this->owner, StaffRepliedNotification::class, function ($notification) {
        $push = $notification->toExpo($this->owner);

        expect($push->data)->toMatchArray([
            'type' => 'message',
            'threadId' => 10,
            'accountId' => $this->account->id,
        ])->and($push->title)->toBe('Laura Motelet')
            ->and($push->body)->toBe('The plumber comes Tuesday.')
            ->and($push->channelId)->toBe('messages');

        return true;
    });
});

test('does not email for replies that were texted or came from the chatbot', function (string $sender, string $channel) {
    Notification::fake();

    hubEvent(['event' => 'message.sent', 'data' => [
        'thread' => ['id' => 10, 'contact_id' => '500', 'subject' => 'Kitchen leak'],
        'message' => ['sender' => $sender, 'channel' => $channel, 'body' => 'Hello'],
    ]])->assertNoContent();

    Notification::assertNothingSent();
})->with([
    'texted' => ['agent', 'sms'],
    'chatbot' => ['bot', 'app'],
]);

test('still pushes to a client who turned reply emails off', function () {
    Notification::fake();
    $this->owner->forceFill(['notification_preferences' => ['message_replies_email' => false]])->save();

    hubEvent(staffReply('Hello'))->assertNoContent();

    Notification::assertSentTo($this->owner, StaffRepliedNotification::class,
        fn ($notification) => $notification->channels === [ExpoChannel::class]);
});

test('still emails a client who turned pushed replies off', function () {
    Notification::fake();
    $this->owner->forceFill(['notification_preferences' => ['message_replies_push' => false]])->save();

    hubEvent(staffReply('Hello'))->assertNoContent();

    Notification::assertSentTo($this->owner, StaffRepliedNotification::class,
        fn ($notification) => $notification->channels === ['mail']);
});

test('says nothing at all to a client who turned both off', function () {
    Notification::fake();
    $this->owner->forceFill(['notification_preferences' => ['message_replies_email' => false, 'message_replies_push' => false]])->save();

    hubEvent(staffReply('Hello'))->assertNoContent();

    Notification::assertNothingSent();
});

test('counts a conversation with a reply the client has not read, until they open it', function () {
    $this->actingAs($this->owner);

    $this->getJson(route('notifications.latest'))
        ->assertJsonPath('messages.count', 1)
        ->assertJsonPath('messages.items.0.id', 10)
        ->assertJsonPath('messages.items.0.lastMessage.author', 'Laura Motelet')
        ->assertJsonPath('count', 1);

    partialGet(route('owner.messages.index', ['thread' => 10]), 'client/Messages', 'conversation')->assertOk();

    $this->getJson(route('notifications.latest'))->assertJsonPath('messages.count', 0);
    expect(ConversationRead::sole())->thread_id->toBe(10)->last_read_message_id->toBe(2);
});

test('the app sees which conversations have unread replies', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.messages.index', $this->account))->assertJsonPath('data.0.unread', true);
    $this->getJson(route('api.v1.accounts.messages.show', [$this->account, 10]))->assertOk();
    $this->getJson(route('api.v1.accounts.messages.index', $this->account))->assertJsonPath('data.0.unread', false);
});
