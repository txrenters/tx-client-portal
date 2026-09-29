<?php

use App\Models\HelpRequest;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create();
});

test('the app sends a help request with its platform and build', function () {
    Sanctum::actingAs($this->user);

    $this->postJson(route('api.v1.help-requests.store'), [
        'topic' => 'something_broken',
        'message' => 'Documents tab spins forever on my phone.',
        'platform' => 'ios',
        'app_version' => '1.0.3 (42)',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.topicLabel', 'Something isn\'t working');

    expect(HelpRequest::sole())->platform->toBe('ios')->app_version->toBe('1.0.3 (42)');
});

test('the app lists the topics and only this login\'s requests', function () {
    Sanctum::actingAs($this->user);
    HelpRequest::factory()->for($this->user)->create();
    HelpRequest::factory()->create();

    $this->getJson(route('api.v1.help-requests.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.topics.0.value', 'signing_in');
});

test('an unknown platform is refused', function () {
    Sanctum::actingAs($this->user);

    $this->postJson(route('api.v1.help-requests.store'), ['topic' => 'other', 'message' => 'Something odd happened.', 'platform' => 'windows'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');
});

test('help requests need a signed-in app', function () {
    $this->getJson(route('api.v1.help-requests.index'))->assertUnauthorized();
});
