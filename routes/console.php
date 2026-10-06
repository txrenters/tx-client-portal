<?php

use App\Models\PortalVisit;
use App\Models\SecurityEvent;
use App\Models\SignInEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Owner approvals that could not reach Propertyware straight away.
Schedule::command('approvals:push')->everyTenMinutes()->withoutOverlapping();

// Old sign-in log entries and visits (see the models' KEEP_DAYS).
Schedule::command('model:prune', ['--model' => [SignInEvent::class, PortalVisit::class, SecurityEvent::class]])->daily();

// The App Review logins start each day from their made-up records.
Schedule::command('demo:reset')->dailyAt('03:00')->timezone('America/Chicago')->withoutOverlapping();
