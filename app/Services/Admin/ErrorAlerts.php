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
 */
class ErrorAlerts
{
    private const QUIET_SECONDS = 3600;

    public function report(Throwable $exception): void
    {
        if (! app()->isProduction() || $exception instanceof PropertywareException) {
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
}
