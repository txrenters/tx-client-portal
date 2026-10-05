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

        $sent = 0;
        $quiet = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            if (in_array($lease->id.'@'.$lease->endDate->toDateString(), $done, strict: true)) {
                continue;
            }

            if ($this->option('seed') && $lease->daysLeft($today) < 80) {
                LeaseExpiryNotice::create(['propertyware_lease_id' => $lease->id, 'end_date' => $lease->endDate, 'recipients' => 0]);
                $quiet++;

                continue;
            }

            try {
                $accounts = $this->recipientsOf($lease, $recipients);
            } catch (PropertywareException $exception) {
                Log::warning('Could not work out who a lease reminder goes to; trying again tomorrow.', [
                    'lease_id' => $lease->id,
                    'reason' => $exception->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            foreach ($accounts as $account) {
                $account->user->notify(new LeaseExpiringNotification(LeaseNoticeText::for($lease, $account, $today)));
            }

            LeaseExpiryNotice::create([
                'propertyware_lease_id' => $lease->id,
                'end_date' => $lease->endDate,
                'notified_at' => now(),
                'recipients' => $accounts->count(),
            ]);
            $sent++;
        }

        $this->info("Sent reminders for {$sent} lease(s); recorded {$quiet} without a reminder; {$skipped} left for tomorrow.");

        return self::SUCCESS;
    }

    /**
     * Everyone a lease's reminder goes to, worked out in full before anything
     * is sent, with each account once.
     *
     * @return Collection<int, ClientAccount>
     *
     * @throws PropertywareException
     */
    private function recipientsOf(ExpiringLease $lease, LeaseExpiryRecipients $recipients): Collection
    {
        return $recipients->owners($lease)
            ->concat($recipients->tenants($lease))
            ->filter(fn (ClientAccount $account): bool => $account->user !== null)
            ->unique(fn (ClientAccount $account): int => $account->id)
            ->values();
    }
}
