<?php

use App\Enums\ClientRole;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\StaffRepliedNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->reply = fn (array $channels = [ExpoChannel::class]) => new StaffRepliedNotification(
        ClientRole::Owner, 10, 'Kitchen leak', 'Laura Motelet', 'The plumber comes Tuesday.', 42, $channels,
    );
});

/** Expo answers with one ticket per message, in the order they were sent. */
function expoAccepts(int $count = 1): void
{
    Http::fake(['exp.host/*' => Http::response([
        'data' => array_map(fn (int $index): array => ['status' => 'ok', 'id' => "ticket-{$index}"], range(1, $count)),
    ])]);
}

test('rings every phone the client registered', function () {
    expoAccepts(2);
    PushToken::factory()->for($this->user)->create(['token' => 'ExponentPushToken[phone-one]']);
    PushToken::factory()->for($this->user)->android()->create(['token' => 'ExponentPushToken[phone-two]']);

    $this->user->notify(($this->reply)());

    Http::assertSent(function (Request $request) {
        expect($request->url())->toBe('https://exp.host/--/api/v2/push/send')
            ->and($request->data())->toHaveCount(2);

        $first = $request->data()[0];

        expect($first['to'])->toBe('ExponentPushToken[phone-one]')
            ->and($first['title'])->toBe('Laura Motelet')
            ->and($first['body'])->toBe('The plumber comes Tuesday.')
            ->and($first['channelId'])->toBe('messages')
            ->and($first['sound'])->toBe('default')
            ->and($first['data'])->toMatchArray(['type' => 'message', 'threadId' => 10, 'accountId' => 42]);

        return true;
    });

    expect(PushToken::whereNotNull('last_pushed_at')->count())->toBe(2);
});

test('drops a phone Expo says is no longer registered', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [
        ['status' => 'ok', 'id' => 'ticket-1'],
        ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
    ]])]);

    PushToken::factory()->for($this->user)->create(['token' => 'ExponentPushToken[alive]']);
    PushToken::factory()->for($this->user)->create(['token' => 'ExponentPushToken[dead]']);

    $this->user->notify(($this->reply)());

    expect(PushToken::pluck('token')->all())->toBe(['ExponentPushToken[alive]']);
});

test('keeps a phone Expo refused for any other reason', function () {
    Log::spy();
    Http::fake(['exp.host/*' => Http::response(['data' => [
        ['status' => 'error', 'message' => 'Message too big', 'details' => ['error' => 'MessageTooBig']],
    ]])]);
    PushToken::factory()->for($this->user)->create();

    $this->user->notify(($this->reply)());

    expect(PushToken::count())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Expo refused'));
});

test('a client with no phone registered is not sent anything', function () {
    Http::fake();

    $this->user->notify(($this->reply)());

    Http::assertNothingSent();
});

test('a push that cannot be delivered never breaks the notification beside it', function () {
    Log::spy();
    Http::fake(['exp.host/*' => Http::response('', 503)]);
    PushToken::factory()->for($this->user)->create();

    $this->user->notify(($this->reply)([ExpoChannel::class, 'mail']));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Could not send a push'));
    expect(PushToken::count())->toBe(1);
});

test('sends more than a hundred phones in separate batches, as Expo requires', function () {
    expoAccepts(100);
    PushToken::factory()->for($this->user)->count(101)->create();

    $this->user->notify(($this->reply)());

    $sizes = [];
    Http::assertSent(function (Request $request) use (&$sizes): bool {
        $sizes[] = count($request->data());

        return true;
    });

    expect($sizes)->toBe([100, 1]);
});
