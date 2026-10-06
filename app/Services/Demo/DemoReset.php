<?php

namespace App\Services\Demo;

use App\Models\ConversationRead;
use App\Models\DemoRecord;
use App\Models\HelpRequest;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkOrderApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Put the demo back to its made-up starting point, so each review starts clean.
 */
final class DemoReset
{
    public const CACHE_GENERATION = 'demo.cache_generation';

    public function handle(): void
    {
        $users = User::query()->where('is_demo', true)->pluck('id');

        DB::transaction(function () use ($users): void {
            DemoRecord::query()->delete();
            WorkOrderApproval::query()->whereIn('user_id', $users)->delete();
            HelpRequest::query()->whereIn('user_id', $users)->delete();
            Referral::query()->whereIn('user_id', $users)->delete();
            ConversationRead::query()->whereIn('user_id', $users)->delete();
            LeaseLedgerEntry::query()->where('lease_id', (string) DemoData::LEASE)->delete();
            LeaseLedgerSync::query()->where('lease_id', (string) DemoData::LEASE)->delete();
        });

        $this->restoreLogins();

        Storage::disk('local')->deleteDirectory('demo');

        // A new prefix abandons every demo cache key at once, on any driver,
        // without flushing the store real users share; the old keys expire.
        Setting::put(self::CACHE_GENERATION, (string) ((int) Setting::valueOf(self::CACHE_GENERATION, '0') + 1));
    }

    /**
     * Bring the demo logins back to how `demo:accounts` left them: present,
     * under their own names, with the chosen password, free of two-factor and
     * suspension, and linked to their made-up contacts. A login whose password
     * was never chosen is skipped, and so is an email a real login has taken.
     */
    private function restoreLogins(): void
    {
        foreach (DemoLogins::ALL as $key => $login) {
            $hash = Setting::valueOf(DemoLogins::hashKey($key));

            if (! is_string($hash) || $hash === '') {
                continue;
            }

            $user = User::query()->where('email', $login['email'])->first();

            if ($user !== null && ! $user->is_demo) {
                continue;
            }

            $user ??= new User(['email' => $login['email']]);
            $user->forceFill([
                'name' => $login['name'],
                'email_verified_at' => $user->email_verified_at ?? now(),
                'is_demo' => true,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'suspended_at' => null,
            ]);

            if (! $user->exists) {
                // Saved with a throwaway password; the stored hash goes in below, as is.
                $user->password = Str::random(40);
            }

            $user->save();
            // A query update skips the model's password cast, which would hash the hash again.
            User::query()->whereKey($user->id)->update(['password' => $hash]);

            $user->clientAccounts()->updateOrCreate(['role' => $login['role']], ['propertyware_id' => (string) $login['contact']]);
        }
    }
}
