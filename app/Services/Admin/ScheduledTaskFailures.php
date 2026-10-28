<?php

namespace App\Services\Admin;

use App\Notifications\ScheduledTaskFailedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Which scheduled commands are failing right now.
 *
 * A command is marked failing when a run fails and cleared when a later run
 * succeeds. The ADMIN_EMAILS owners are emailed when a command starts failing,
 * not on every failed run, so a command that fails every ten minutes sends
 * one email until it recovers.
 */
class ScheduledTaskFailures
{
    private const KEY = 'scheduler:failures';

    /**
     * Mark a command failing, and tell the owners if it was not already.
     */
    public function record(string $command, string $reason): void
    {
        $failures = $this->failing();
        $alreadyFailing = isset($failures[$command]);

        $failures[$command] = [
            'command' => $command,
            'since' => $failures[$command]['since'] ?? now()->toIso8601String(),
            'lastFailedAt' => now()->toIso8601String(),
            'reason' => Str::limit($reason, 500),
        ];

        Cache::forever(self::KEY, $failures);

        $owners = array_filter((array) config('services.admin_emails', []));

        if (! $alreadyFailing && $owners !== []) {
            Notification::route('mail', $owners)->notify(new ScheduledTaskFailedNotification($command, $reason));
        }
    }

    /**
     * Clear a command once it runs successfully again.
     */
    public function clear(string $command): void
    {
        $failures = $this->failing();

        if (isset($failures[$command])) {
            unset($failures[$command]);
            Cache::forever(self::KEY, $failures);
        }
    }

    /**
     * The commands failing now, keyed by command.
     *
     * @return array<string, array{command: string, since: string, lastFailedAt: string, reason: string}>
     */
    public function failing(): array
    {
        return Cache::get(self::KEY, []);
    }
}
