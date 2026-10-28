<?php

use App\Models\User;
use App\Notifications\ScheduledTaskFailedNotification;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.admin_emails' => ['owner@texasrenters.com']]);
});

function failScheduledRun(string $command, string $reason = 'Propertyware is down.'): void
{
    $task = app(Schedule::class)->command($command);
    $task->exitCode = 1;

    event(new ScheduledTaskFinished($task, 1.0));
    event(new ScheduledTaskFailed($task, new RuntimeException($reason)));
}

function succeedScheduledRun(string $command): void
{
    $task = app(Schedule::class)->command($command);
    $task->exitCode = 0;

    event(new ScheduledTaskFinished($task, 1.0));
}

test('emails the owners once when a scheduled job starts failing', function () {
    Notification::fake();

    failScheduledRun('approvals:push');
    failScheduledRun('approvals:push');

    Notification::assertSentOnDemandTimes(ScheduledTaskFailedNotification::class, 1);
    Notification::assertSentOnDemand(
        ScheduledTaskFailedNotification::class,
        fn ($notification, $channels, AnonymousNotifiable $notifiable): bool => $notification->command === 'approvals:push'
            && $notifiable->routes['mail'] === ['owner@texasrenters.com'],
    );
});

test('emails again when a job fails after it recovered', function () {
    Notification::fake();

    failScheduledRun('approvals:push');
    succeedScheduledRun('approvals:push');
    failScheduledRun('approvals:push');

    Notification::assertSentOnDemandTimes(ScheduledTaskFailedNotification::class, 2);
});

test('the integrations page lists scheduled jobs failing now', function () {
    Notification::fake();
    failScheduledRun('approvals:push', 'Propertyware refused the approval.');
    failScheduledRun('leases:notify-expiring');
    succeedScheduledRun('leases:notify-expiring');
    $owner = User::factory()->create(['email' => 'owner@texasrenters.com']);

    $this->actingAs($owner)
        ->get(route('admin.integrations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('queue.failingSchedules', 1)
            ->where('queue.failingSchedules.0.command', 'approvals:push')
            ->where('queue.failingSchedules.0.reason', 'Propertyware refused the approval.'));
});
