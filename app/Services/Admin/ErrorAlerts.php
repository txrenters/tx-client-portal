<?php

namespace App\Services\Admin;

use App\Notifications\ErrorReportedNotification;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails the ADMIN_EMAILS owners about unexpected errors in production, so
 * they are seen before a client reports them.
 *
 * The same error (its class and where it was thrown) is emailed at most once
 * an hour, so a broken page that many people open sends one email. Propertyware
 * being unavailable is left out: it is the vendor's outage, shown on the
 * Integrations page, and would otherwise email from every page that reads it.
 * So is the scheduler's "failed with exit code" error: ScheduledTaskFailures
 * already emails once when a command starts failing, until it recovers.
 */
class ErrorAlerts
{
    private const QUIET_SECONDS = 3600;

    public function report(Throwable $exception): void
    {
        if (! app()->isProduction() || $exception instanceof PropertywareException || $this->isScheduledCommandFailure($exception)) {
            return;
        }

        $owners = array_filter((array) config('services.admin_emails', []));
        $signature = sha1($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());

        if ($owners === [] || ! Cache::add('error-alert:'.$signature, true, self::QUIET_SECONDS)) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();

        try {
            Notification::route('mail', $owners)->notify(new ErrorReportedNotification(
                $exception::class,
                $exception->getMessage(),
                str_replace(base_path().'/', '', $exception->getFile()).':'.$exception->getLine(),
                $request === null ? null : $request->method().' '.$request->path(),
            ));
        } catch (Throwable $mailFailure) {
            // The error itself is still in the log; an alert that cannot be sent must not hide it.
            Log::warning('Could not email the error alert.', ['message' => $mailFailure->getMessage()]);
        }
    }

    /**
     * The scheduler's own error for a command that exited with a failure code.
     */
    private function isScheduledCommandFailure(Throwable $exception): bool
    {
        return str_ends_with($exception->getFile(), 'Console'.DIRECTORY_SEPARATOR.'Scheduling'.DIRECTORY_SEPARATOR.'ScheduleRunCommand.php');
    }
}
