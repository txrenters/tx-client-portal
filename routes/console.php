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

// Old sign-in log entries and visits (see the models' KEEP_DAYS).
Schedule::command('model:prune', ['--model' => [SignInEvent::class, PortalVisit::class, SecurityEvent::class]])->daily();

Artisan::command('notifications:prune', function () {
    $deleted = DatabaseNotification::where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Deleted {$deleted} notification(s) older than 90 days.");
})->purpose('Delete stored notifications older than 90 days');

Schedule::command('notifications:prune')->daily();
