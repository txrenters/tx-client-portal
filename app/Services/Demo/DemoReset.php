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

        Storage::disk('local')->deleteDirectory('demo');

        // A new prefix abandons every demo cache key at once, on any driver,
        // without flushing the store real users share; the old keys expire.
        Setting::put(self::CACHE_GENERATION, (string) ((int) Setting::valueOf(self::CACHE_GENERATION, '0') + 1));
    }
}
