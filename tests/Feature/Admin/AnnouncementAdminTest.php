<?php

use App\Enums\ClientRole;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\Announcements\AnnouncementBoard;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

function announcementDetails(array $overrides = []): array
{
    return ['title' => 'Office closed Friday', 'body' => 'We are closed for the holiday.', 'audience' => 'all', 'tone' => 'info', ...$overrides];
}

test('staff post an announcement, and emailing it is queued once', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.announcements.store'), announcementDetails(['send_email' => true]))
        ->assertRedirect();

    $announcement = Announcement::sole();
    expect($announcement)->created_by->toBe($this->admin->id)->emailed_at->not->toBeNull()->pushed_at->toBeNull();
    Queue::assertPushed(SendAnnouncement::class, fn (SendAnnouncement $job) => $job->email && ! $job->push);

    // Saving again with the box ticked does not email everyone twice.
    $this->actingAs($this->admin)
        ->put(route('admin.announcements.update', $announcement), announcementDetails(['title' => 'Changed', 'send_email' => true]))
        ->assertRedirect();

    Queue::assertPushed(SendAnnouncement::class, 1);
});

test('an announcement cannot end before it starts', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.announcements.store'), announcementDetails(['starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()]))
        ->assertSessionHasErrors('ends_at');
});

test('the send reaches confirmed, active clients in the audience only', function () {
    Notification::fake();
    $owner = User::factory()->owner()->create();
    $resident = User::factory()->resident()->create();
    $suspended = User::factory()->owner()->create(['suspended_at' => now()]);
    $unconfirmed = User::factory()->unverified()->owner()->create();
    $announcement = Announcement::factory()->audience('owner')->create();

    (new SendAnnouncement($announcement->id, email: true, push: false))->handle(app(AnnouncementBoard::class));

    Notification::assertSentTo($owner, AnnouncementNotification::class, fn ($notification, $channels) => $channels === ['mail']);
    Notification::assertNotSentTo([$resident, $suspended, $unconfirmed], AnnouncementNotification::class);
});

test('taking an announcement down stops it showing', function () {
    $announcement = Announcement::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.announcements.end', $announcement))->assertRedirect();

    expect($announcement->fresh()->isLive())->toBeFalse();
});

test('the admin list says how many each audience reaches', function () {
    User::factory()->owner()->create();
    User::factory()->resident()->create();

    $this->actingAs($this->admin)->get(route('admin.announcements.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Announcements')
            ->where('audienceSizes.all', 2)
            ->where('audienceSizes.owner', 1));
});

test('clients cannot post announcements', function () {
    $client = User::factory()->owner()->create();

    $this->actingAs($client)->post(route('admin.announcements.store'), announcementDetails())->assertForbidden();

    expect(Announcement::count())->toBe(0);
});

test('the App Review logins are never in an announcement audience', function () {
    $real = User::factory()->create(['email_verified_at' => now()]);
    $demo = User::factory()->demo()->create(['email_verified_at' => now()]);
    foreach ([$real, $demo] as $user) {
        ClientAccount::factory()->for($user)->create(['role' => ClientRole::Owner]);
    }

    expect(app(AnnouncementBoard::class)->audienceQuery('all')->pluck('id')->all())->toBe([$real->id]);
});
