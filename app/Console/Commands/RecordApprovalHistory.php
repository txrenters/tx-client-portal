<?php

namespace App\Console\Commands;

use App\Services\Propertyware\ApprovalHistory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Propertyware\PropertywareUnavailableException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Signature('approvals:history {--since= : Read work orders changed since this date, such as 2026-01-01, instead of since the last run}')]
#[Description('Keep a copy of work order approvals and approval comments that changed in Propertyware')]
class RecordApprovalHistory extends Command
{
    /**
     * Cache key holding when Propertyware was first found unavailable, until a run succeeds.
     */
    public const UNAVAILABLE_SINCE = 'propertyware:approval-history:unavailable-since';

    /**
     * How long Propertyware may be unavailable before a run counts as failed.
     *
     * It goes down for short spells, often overnight; nothing is lost meanwhile,
     * since the next run reads everything changed since the last complete one.
     */
    public const UNAVAILABLE_GRACE_MINUTES = 120;

    /**
     * Execute the console command.
     */
    public function handle(ApprovalHistory $history, PropertywareSoap $soap): int
    {
        if (! $soap->isConfigured()) {
            $this->info('Propertyware SOAP credentials are not configured.');

            return self::SUCCESS;
        }

        $since = $this->option('since');

        try {
            ['checked' => $checked, 'recorded' => $recorded, 'failed' => $failed] = $history->record(
                is_string($since) && $since !== '' ? Carbon::parse($since) : null,
            );
        } catch (PropertywareUnavailableException $exception) {
            return $this->waitOutUnavailable($exception);
        } catch (PropertywareException $exception) {
            $this->error('Could not list changed work orders: '.$exception->getMessage());

            return self::FAILURE;
        }

        Cache::forget(self::UNAVAILABLE_SINCE);

        $this->info("Checked {$checked} changed work order(s); kept {$recorded} new approval version(s); {$failed} could not be read.");

        return self::SUCCESS;
    }

    /**
     * Count the run as done while Propertyware has been unavailable only briefly,
     * and as failed once it has been unavailable for longer than the grace period.
     */
    private function waitOutUnavailable(PropertywareUnavailableException $exception): int
    {
        Cache::add(self::UNAVAILABLE_SINCE, now()->toIso8601String());
        $since = Carbon::parse(Cache::get(self::UNAVAILABLE_SINCE));

        if ($since->diffInMinutes(now()) < self::UNAVAILABLE_GRACE_MINUTES) {
            $this->warn('Propertyware is unavailable; the next run catches up: '.$exception->getMessage());

            return self::SUCCESS;
        }

        $this->error("Propertyware has been unavailable since {$since->toDateTimeString()}: ".$exception->getMessage());

        return self::FAILURE;
    }
}
