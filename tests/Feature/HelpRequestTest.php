<?php

use App\Enums\HelpTopic;
use App\Models\HelpRequest;
use App\Models\User;
use App\Notifications\HelpRequestReceivedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
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
