<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Owner approvals that could not reach Propertyware straight away.
Schedule::command('approvals:push')->everyTenMinutes()->withoutOverlapping();
