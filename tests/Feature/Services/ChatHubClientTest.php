<?php

use App\Services\ChatHub\ChatHubClient;
use App\Services\ChatHub\ChatHubException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $this->hub = new ChatHubClient('https://support.test/api/v1', 'hub-token');
});

test('reads again when the first attempt never reaches the hub', function () {
    Http::fake([
        'support.test/api/v1/threads/7' => Http::sequence()
            ->pushFailedConnection('Resolving timed out after 5000 milliseconds')
            ->push(['data' => ['id' => 7, 'subject' => 'Leak']]),
    ]);

    expect($this->hub->thread(7))->toBe(['id' => 7, 'subject' => 'Leak']);

    Http::assertSentCount(2);
});

test('gives up after a second failed connection', function () {
    Http::fake([
        'support.test/api/v1/threads/7' => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection(),
    ]);

    expect(fn () => $this->hub->thread(7))->toThrow(ChatHubException::class, 'Could not reach the messaging hub.');

    Http::assertSentCount(2);
});

test('does not read again when the hub answers with an error', function () {
    Http::fake(['support.test/api/v1/threads/7' => Http::response([], 500)]);

    expect(fn () => $this->hub->thread(7))->toThrow(ChatHubException::class, 'The messaging hub answered 500.');

    Http::assertSentCount(1);
});

test('never sends a client message twice after a failed connection', function () {
    Http::fake(['support.test/api/v1/threads/7/messages' => Http::failedConnection()]);

    expect(fn () => $this->hub->postClientMessage(7, 'Hello', 'key-1'))->toThrow(ChatHubException::class, 'Could not reach the messaging hub.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    Http::assertSentCount(1);
});
