<?php

use App\Models\HelpRequest;
use App\Models\HelpRequestAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

test('the app attaches screenshots and gets links it can fetch with its token', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->user);

    $response = $this->post(route('api.v1.help-requests.store'), [
        'topic' => 'something_broken',
        'message' => 'Documents tab spins forever on my phone.',
        'platform' => 'android',
        'attachments' => [UploadedFile::fake()->image('spinner.jpg')],
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.attachments.0.name', 'spinner.jpg')
        ->assertJsonPath('data.attachments.0.isImage', true);

    $attachment = HelpRequestAttachment::sole();

    expect($response->json('data.attachments.0.url'))->toBe(route('api.v1.help-requests.attachments.show', $attachment));

    $this->get(route('api.v1.help-requests.attachments.show', $attachment))->assertOk();

    Sanctum::actingAs(User::factory()->create());
    $this->get(route('api.v1.help-requests.attachments.show', $attachment))->assertNotFound();
});
