<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Models\ApprovalPushNotice;
use App\Models\ClientAccount;
use App\Notifications\ApprovalNeededNotification;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('approvals:notify {--seed : First run: record what is already waiting without pushing it}')]
#[Description('Push owners the work orders newly waiting for their approval')]
class NotifyPendingApprovals extends Command
{
    /**
     * What is waiting is decided exactly as the bell and the Work Orders badge
     * decide it, so a push never names something the app does not show.
     */
    public function handle(PropertywareDirectory $directory, WorkOrderApprovals $approvals): int
    {
        $pushed = 0;
        $skipped = 0;

        // The App Review demo owner holds made-up records and a fake
        // Propertyware contact; looking it up would hit the real API every run.
        $owners = ClientAccount::query()
            ->where('role', ClientRole::Owner)
            ->whereHas('user', fn ($user) => $user->where('is_demo', false))
            ->with('user.pushTokens')
            ->get();

        foreach ($owners as $account) {
            try {
                $waiting = $approvals->summaryFor($directory->scopeFor($account), $account, limit: 100)['items'];
            } catch (PropertywareException $exception) {
                // Tried again on the next run; nothing is recorded for this owner.
                Log::warning('Could not read work orders awaiting approval.', [
                    'client_account_id' => $account->id,
                    'reason' => $exception->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            $told = ApprovalPushNotice::query()
                ->where('client_account_id', $account->id)
                ->pluck('work_order_id')
                ->all();

            foreach ($waiting as $workOrder) {
                $id = (string) $workOrder['id'];

                if (in_array($id, $told, strict: true)) {
                    continue;
                }

                // Recorded even when not pushed, so switching pushes back on
                // later does not bring a flood of old work orders with it.
                $push = ! $this->option('seed') && $account->user->wantsNotification('work_order_approvals_push');

                if ($push) {
                    $account->user->notify(new ApprovalNeededNotification($account->id, $workOrder));
                    $pushed++;
                }

                ApprovalPushNotice::create([
                    'client_account_id' => $account->id,
                    'work_order_id' => $id,
                    'pushed_at' => $push ? now() : null,
                ]);
            }
        }

        $this->info("Pushed {$pushed} approval(s); could not read {$skipped} owner(s).");

        return self::SUCCESS;
    }
}
