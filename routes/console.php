<?php

use App\Models\PortalVisit;
use App\Models\SecurityEvent;
use App\Models\SignInEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Owner approvals that could not reach Propertyware straight away.
Schedule::command('approvals:push')->everyTenMinutes()->withoutOverlapping();

// Copies of approvals and approval comments changed in Propertyware, which keeps no history of them.
Schedule::command('approvals:history')->everyThirtyMinutes()->withoutOverlapping();

// Tell owners about work orders newly waiting for their approval; daytime only,
// so nobody is woken for it. Overnight ones go out at 8.
Schedule::command('approvals:notify')->everyThirtyMinutes()->between('8:00', '20:00')->timezone('America/Chicago')->withoutOverlapping();

// Owners and tenants whose work orders were scheduled, completed or canceled;
// daytime only, like approvals.
Schedule::command('work-orders:notify-updates')->everyThirtyMinutes()->between('8:00', '20:00')->timezone('America/Chicago')->withoutOverlapping();

// Owners and tenants whose lease ends within 90 days.
Schedule::command('leases:notify-expiring')->dailyAt('09:00')->timezone('America/Chicago')->withoutOverlapping();

// Old sign-in log entries and visits (see the models' KEEP_DAYS).
Schedule::command('model:prune', ['--model' => [SignInEvent::class, PortalVisit::class, SecurityEvent::class]])->daily();

Artisan::command('notifications:prune', function () {
    $deleted = DatabaseNotification::where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Deleted {$deleted} notification(s) older than 90 days.");
})->purpose('Delete stored notifications older than 90 days');

Schedule::command('notifications:prune')->daily();

// The App Review logins start each day from their made-up records.
Schedule::command('demo:reset')->dailyAt('03:00')->timezone('America/Chicago')->withoutOverlapping();
