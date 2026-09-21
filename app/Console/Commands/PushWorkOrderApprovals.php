<?php

namespace App\Console\Commands;

use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('approvals:push')]
#[Description('Send owner approvals that have not reached Propertyware yet')]
class PushWorkOrderApprovals extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WorkOrderApprovals $approvals): int
    {
        if (! config('services.propertyware.approval_push')) {
            $this->info('Sending approvals to Propertyware is turned off (PROPERTYWARE_APPROVAL_PUSH).');

            return self::SUCCESS;
        }

        ['sent' => $sent, 'failed' => $failed] = $approvals->pushPending();

        $this->info("Sent {$sent} approval(s) to Propertyware; {$failed} still waiting.");

        return self::SUCCESS;
    }
}
