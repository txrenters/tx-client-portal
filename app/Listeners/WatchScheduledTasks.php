<?php

namespace App\Listeners;

use App\Services\Admin\ScheduledTaskFailures;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * Marks scheduled commands failing or recovered as they run.
 *
 * A command that throws, or exits with a failure code, raises
 * ScheduledTaskFailed; a run that finished with success clears it.
 */
class WatchScheduledTasks
{
    public function __construct(private ScheduledTaskFailures $failures) {}

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        if ((int) $event->task->exitCode === 0) {
            $this->failures->clear($this->name($event->task));
        }
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->failures->record($this->name($event->task), $event->exception->getMessage());
    }

    /**
     * The command as written in routes/console.php, such as "approvals:push".
     */
    private function name(Event $task): string
    {
        if (filled($task->description)) {
            return $task->description;
        }

        return trim(Str::after((string) $task->command, "'artisan'")) ?: (string) $task->command;
    }
}
