<?php

use App\Http\Middleware\DemoMode;
use App\Services\ChatHub\ChatHubClient;
use App\Services\ChatHub\ChatHubException;
use App\Services\Demo\DemoData;

beforeEach(function () {
    DemoMode::enter();
    $this->hub = app(ChatHubClient::class);
});

test('lists only the demo contact\'s own thread, with its last message', function () {
    $threads = $this->hub->threads((string) DemoData::TENANT_CONTACT);

    expect(collect($threads['data'])->pluck('id')->all())->toBe([DemoData::TENANT_THREAD])
        ->and($threads['data'][0]['last_message']['sender'])->toBe('agent')
        ->and($threads['meta'])->toMatchArray(['current_page' => 1, 'last_page' => 1, 'total' => 1]);
});

test('reads a thread and its messages oldest first', function () {
    expect($this->hub->thread(DemoData::OWNER_THREAD)['contact_id'])->toBe((string) DemoData::OWNER_CONTACT)
        ->and(collect($this->hub->messages(DemoData::OWNER_THREAD))->pluck('direction')->all())->toBe(['inbound', 'outbound']);
});

test('opens a thread and posts to it without leaving the portal', function () {
    $thread = $this->hub->openThread((string) DemoData::OWNER_CONTACT, null, 'leasing');
    $this->hub->postClientMessage($thread['id'], 'When will the vacant home be listed?', 'key-1');

    expect($thread['subject'])->toBe('Leasing question')
        ->and(collect($this->hub->threads((string) DemoData::OWNER_CONTACT)['data'])->pluck('id'))->toContain($thread['id'])
        ->and(collect($this->hub->messages($thread['id']))->pluck('body')->all())->toBe(['When will the vacant home be listed?']);
});

test('a thread that is not the demo\'s is not found', function () {
    $this->hub->thread(1);
})->throws(ChatHubException::class, '404');

test('remembers the sms preference', function () {
    expect($this->hub->setSmsReplies((string) DemoData::OWNER_CONTACT, true)['sms_replies'])->toBeTrue();
});
