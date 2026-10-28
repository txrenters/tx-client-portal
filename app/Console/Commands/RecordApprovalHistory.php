<?php

namespace App\Console\Commands;

use App\Services\Propertyware\ApprovalHistory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('approvals:history {--since= : Read work orders changed since this date, such as 2026-01-01, instead of since the last run}')]
#[Description('Keep a copy of work order approvals and approval comments that changed in Propertyware')]
class RecordApprovalHistory extends Command
{
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
        } catch (PropertywareException $exception) {
            $this->error('Could not list changed work orders: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Checked {$checked} changed work order(s); kept {$recorded} new approval version(s); {$failed} could not be read.");

        return self::SUCCESS;
    }
}
