<?php

use App\Notifications\ErrorReportedNotification;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.admin_emails' => ['owner@texasrenters.com']]);
    app()->detectEnvironment(fn (): string => 'production');
});

function throwAt(int $line): RuntimeException
{
    return match ($line) {
        1 => new RuntimeException('Ledger total is not a number.'),
        2 => new RuntimeException('Something else broke.'),
    };
}

test('emails the owners an unexpected error once an hour', function () {
    Notification::fake();

    report(throwAt(1));
    report(throwAt(1));
    $this->travel(61)->minutes();
    report(throwAt(1));

    Notification::assertSentOnDemandTimes(ErrorReportedNotification::class, 2);
    Notification::assertSentOnDemand(
        ErrorReportedNotification::class,
        fn (ErrorReportedNotification $notification): bool => $notification->exceptionMessage === 'Ledger total is not a number.'
            && str_starts_with($notification->location, 'tests/Feature/ErrorAlertTest.php:'),
    );
});

test('emails each different error', function () {
    Notification::fake();

    report(throwAt(1));
    report(throwAt(2));

    Notification::assertSentOnDemandTimes(ErrorReportedNotification::class, 2);
});

test('does not email about Propertyware being unavailable', function () {
    Notification::fake();

    report(new PropertywareException('Propertyware returned 503.'));

    Notification::assertNothingSent();
});

test('does not email outside production', function () {
    Notification::fake();
    app()->detectEnvironment(fn (): string => 'local');

    report(throwAt(1));

    Notification::assertNothingSent();
});

test('does not email the scheduler\'s failed-command error, which the failing job alert already covers', function () {
    Notification::fake();

    $failure = new Exception("Scheduled command ['/usr/local/bin/php' 'artisan' approvals:history] failed with exit code [1].");
    (new ReflectionProperty(Exception::class, 'file'))->setValue($failure, base_path('vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php'));

    report($failure);

    Notification::assertNothingSent();
});
