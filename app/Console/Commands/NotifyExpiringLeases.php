<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\LeaseExpiryNotice;
use App\Notifications\LeaseExpiringNotification;
use App\Services\Leases\ExpiringLease;
use App\Services\Leases\ExpiringLeases;
use App\Services\Leases\LeaseExpiryRecipients;
use App\Services\Leases\LeaseNoticeText;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('leases:notify-expiring {--seed : First run: tell only leases 80–90 days out and record the rest without telling anyone}')]
#[Description('Tell owners and tenants about leases ending within 90 days')]
class NotifyExpiringLeases extends Command
{
    /**
     * Leases ending anywhere inside the next 90 days are considered, not only
     * those on day 90, so a morning the job did not run is caught up the next.
     * The record stops anyone hearing about the same lease twice.
     */
    public function handle(ExpiringLeases $expiring, LeaseExpiryRecipients $recipients): int
    {
        $today = CarbonImmutable::today();

        try {
            $leases = $expiring->between($today, $today->addDays(90));
        } catch (PropertywareException $exception) {
            $this->error("Could not read leases from Propertyware: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $done = LeaseExpiryNotice::query()
            ->whereIn('propertyware_lease_id', array_map(fn (ExpiringLease $lease): int => $lease->id, $leases))
            ->get()
            ->map(fn (LeaseExpiryNotice $record): string => $record->propertyware_lease_id.'@'.$record->end_date->toDateString())
            ->all();

        $seed = $this->option('seed') || ! LeaseExpiryNotice::query()->exists();

        if ($seed && ! $this->option('seed')) {
            $this->info('No reminders have been recorded yet, so this first run is being treated as a seed.');
        }

        $sent = 0;
        $quiet = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($leases as $lease) {
            $key = $lease->id.'@'.$lease->endDate->toDateString();

            if (in_array($key, $done, strict: true)) {
                continue;
            }

            $done[] = $key;

            if ($seed && $lease->daysLeft($today) < 80) {
                LeaseExpiryNotice::create(['propertyware_lease_id' => $lease->id, 'end_date' => $lease->endDate, 'recipients' => 0]);
                $quiet++;

                continue;
            }

            try {
                $notices = $this->noticesFor($lease, $recipients, $today);
            } catch (Throwable $exception) {
                if ($exception instanceof PropertywareException) {
                    Log::warning('Could not work out the reminders for a lease; trying again tomorrow.', [
                        'lease_id' => $lease->id,
                        'reason' => $exception->getMessage(),
                    ]);
                } else {
                    report($exception);
                }

                $skipped++;

                continue;
            }

            $delivered = 0;

            try {
                foreach ($notices as ['account' => $account, 'notice' => $notice]) {
                    $account->user->notify(new LeaseExpiringNotification($notice));
                    $delivered++;
                }

                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }

            // Recorded even after a failed send: a missed copy is better than
            // telling everyone before the failure again every morning.
            LeaseExpiryNotice::create([
                'propertyware_lease_id' => $lease->id,
                'end_date' => $lease->endDate,
                'notified_at' => now(),
                'recipients' => $delivered,
            ]);
        }

        $this->info("Sent reminders for {$sent} lease(s); {$failed} failed part-way; recorded {$quiet} without a reminder; {$skipped} left for tomorrow.");

        return self::SUCCESS;
    }

    /**
     * What each person a lease's reminder goes to will read, all worked out
     * before anything is sent, with each account once.
     *
     * @return Collection<int, array{account: ClientAccount, notice: array<string, mixed>}>
     *
     * @throws PropertywareException
     */
    private function noticesFor(ExpiringLease $lease, LeaseExpiryRecipients $recipients, CarbonImmutable $today): Collection
    {
        return $recipients->owners($lease)
            ->concat($recipients->tenants($lease))
            ->filter(fn (ClientAccount $account): bool => $account->user !== null)
            ->unique(fn (ClientAccount $account): int => $account->id)
            ->map(fn (ClientAccount $account): array => ['account' => $account, 'notice' => LeaseNoticeText::for($lease, $account, $today)])
            ->values();
    }
}
