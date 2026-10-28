<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\RentReminderNotice;
use App\Notifications\RentDueNotification;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('rent:remind')]
#[Description('Remind tenants who still owe rent before late fees start')]
class RemindRentDue extends Command
{
    /**
     * Rent is due on the 1st, and late fees start the day after the grace day
     * in the lease's late fee rule. A tenant with a balance on any day up to
     * then is reminded once that month, so a missed morning is caught up.
     */
    public function handle(PropertywareDirectory $directory): int
    {
        $today = CarbonImmutable::today(config('app.display_timezone'));
        $sent = 0;
        $skipped = 0;

        // The App Review demo logins hold made-up records and a fake
        // Propertyware contact; looking them up would hit the real API.
        $accounts = ClientAccount::query()
            ->where('role', ClientRole::Resident)
            ->whereHas('user', fn ($user) => $user->where('is_demo', false)->whereNull('suspended_at'))
            ->whereDoesntHave('rentReminders', fn ($reminders) => $reminders->where('month', $today->format('Y-m')))
            ->with('user')
            ->get();

        foreach ($accounts as $account) {
            try {
                $lease = $directory->leaseFor($directory->scopeFor($account));
            } catch (PropertywareException $exception) {
                Log::warning('Could not read a lease for the rent reminder; trying again tomorrow.', [
                    'client_account_id' => $account->id,
                    'reason' => $exception->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            $graceDay = $lease?->lateFeeGraceDay() ?? 3;

            if ($lease === null || ! $lease->active || ($lease->balance ?? 0) <= 0 || $today->day > $graceDay) {
                continue;
            }

            $account->user->notify(new RentDueNotification($account, $lease, $today->setDay($graceDay)));
            $sent++;

            RentReminderNotice::create([
                'client_account_id' => $account->id,
                'propertyware_lease_id' => $lease->id,
                'month' => $today->format('Y-m'),
                'balance' => $lease->balance,
            ]);
        }

        $this->info("Reminded {$sent} tenant(s) about rent; could not read {$skipped} lease(s).");

        return self::SUCCESS;
    }
}
