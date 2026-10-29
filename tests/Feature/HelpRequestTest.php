<?php

use App\Enums\HelpTopic;
use App\Models\HelpRequest;
use App\Models\HelpRequestAttachment;
use App\Models\User;
use App\Notifications\HelpRequestReceivedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->user = User::factory()->resident()->create();
});

test('a client asks for help from the web and staff are emailed', function () {
    Notification::fake();

    $this->actingAs($this->user)
        ->post(route('help.store'), ['topic' => 'signing_in', 'message' => 'My passkey stopped working on my laptop.'])
        ->assertRedirect();

    expect(HelpRequest::sole())
        ->user_id->toBe($this->user->id)
        ->topic->toBe(HelpTopic::SigningIn)
        ->platform->toBe('web');

    Notification::assertSentTo(
        new AnonymousNotifiable,
        HelpRequestReceivedNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['staff@texasrenters.com'],
    );
});

test('a help request needs a topic and enough detail to act on', function () {
    $this->actingAs($this->user)
        ->post(route('help.store'), ['topic' => 'nonsense', 'message' => 'Help'])
        ->assertSessionHasErrors(['topic', 'message' => 'Tell us a little more so we can help.']);

    expect(HelpRequest::count())->toBe(0);
});

test('someone who has not confirmed their email can still ask for help', function () {
    $unconfirmed = User::factory()->unverified()->create();

    $this->actingAs($unconfirmed)->get(route('help'))->assertOk();
});

test('the help page lists only the client\'s own requests, and the staff note once resolved', function () {
    HelpRequest::factory()->for($this->user)->create(['staff_note' => 'Draft, not ready']);
    HelpRequest::factory()->for($this->user)->resolved()->create(['staff_note' => 'Fixed it for you.']);
    HelpRequest::factory()->create();

    $this->actingAs($this->user)->get(route('help'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('client/Help')
            ->has('topics', count(HelpTopic::cases()))
            ->has('requests.data', 2)
            ->where('requests.data.0.staffNote', 'Fixed it for you.')
            ->where('requests.data.1.staffNote', null));
});

test('the staff email escapes what the client wrote', function () {
    $request = HelpRequest::factory()->create(['message' => '<script>alert(1)</script>']);

    $html = (string) (new HelpRequestReceivedNotification($request))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>alert(1)</script>');
});

test('a client attaches screenshots so staff can see what they saw', function () {
    Storage::fake('local');
    Notification::fake();

    $this->actingAs($this->user)
        ->post(route('help.store'), [
            'topic' => 'something_broken',
            'message' => 'The documents page is blank on my laptop.',
            'attachments' => [UploadedFile::fake()->image('blank-page.png'), UploadedFile::fake()->create('error.pdf', 120, 'application/pdf')],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $attachments = HelpRequest::sole()->attachments;

    expect($attachments)->toHaveCount(2)
        ->and($attachments->first()->name)->toBe('blank-page.png')
        ->and($attachments->first()->isImage())->toBeTrue();

    Storage::disk('local')->assertExists($attachments->pluck('path')->all());

    Notification::assertSentTo(new AnonymousNotifiable, HelpRequestReceivedNotification::class,
        fn ($notification) => str_contains(implode(' ', $notification->toMail(new AnonymousNotifiable)->introLines), 'attached 2 files'));
});

test('a large photo attached to a help request is kept scaled down', function () {
    Storage::fake('local');
    Notification::fake();

    $this->actingAs($this->user)
        ->post(route('help.store'), [
            'topic' => 'something_broken',
            'message' => 'The documents page is blank on my phone.',
            'attachments' => [UploadedFile::fake()->image('phone.jpg', 4000, 3000)],
        ])
        ->assertSessionHasNoErrors();

    $attachment = HelpRequest::sole()->attachments->sole();
    $size = getimagesizefromstring(Storage::disk('local')->get($attachment->path));

    expect([$size[0], $size[1]])->toBe([2000, 1500])
        ->and($attachment->name)->toBe('phone.jpg')
        ->and($attachment->mime_type)->toBe('image/jpeg');
});

test('a help request takes at most five files, and only pictures and PDFs', function () {
    Storage::fake('local');

    $this->actingAs($this->user)
        ->post(route('help.store'), [
            'topic' => 'other',
            'message' => 'Here is everything I have about it.',
            'attachments' => array_map(fn (int $i) => UploadedFile::fake()->image("shot-{$i}.png"), range(1, 6)),
        ])
        ->assertSessionHasErrors(['attachments' => 'Attach up to 5 files.']);

    $this->actingAs($this->user)
        ->post(route('help.store'), [
            'topic' => 'other',
            'message' => 'Here is a script that shows the problem.',
            'attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')],
        ])
        ->assertSessionHasErrors('attachments.0');

    expect(HelpRequest::count())->toBe(0);
});

test('the help page lists each request\'s attachments, and only their owner can open them', function () {
    Storage::fake('local');
    $request = HelpRequest::factory()->for($this->user)->create();
    $attachment = HelpRequestAttachment::factory()->for($request)->create(['path' => 'help-requests/1/shot.png']);
    Storage::disk('local')->put('help-requests/1/shot.png', 'png-bytes');

    $this->actingAs($this->user)->get(route('help'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.data.0.attachments.0.name', 'screenshot.png')
            ->where('requests.data.0.attachments.0.isImage', true)
            ->where('requests.data.0.attachments.0.url', route('help.attachments.show', $attachment)));

    $this->actingAs($this->user)->get(route('help.attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs(User::factory()->create())->get(route('help.attachments.show', $attachment))->assertNotFound();
});
