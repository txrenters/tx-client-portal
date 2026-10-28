<?php

namespace App\Console\Commands;

use App\Enums\ClientRole;
use App\Enums\WorkOrderStatus;
use App\Models\ClientAccount;
use App\Models\WorkOrderStatusNotice;
use App\Notifications\WorkOrderUpdatedNotification;
use App\Services\Propertyware\Data\WorkOrder;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('work-orders:notify-updates')]
#[Description('Tell owners and tenants when their work orders are scheduled, completed or canceled')]
class NotifyWorkOrderUpdates extends Command
{
    /**
     * Statuses a client is told about. A work order going back to open is
     * recorded without telling anyone.
     */
    private const TOLD = [WorkOrderStatus::Scheduled, WorkOrderStatus::Completed, WorkOrderStatus::Canceled];

    /**
     * A work order seen for the first time is only recorded, so the first run,
     * and a new account, do not bring a flood of old work orders.
     */
    public function handle(PropertywareDirectory $directory, MaintenanceSummary $maintenance): int
    {
        $sent = 0;
        $skipped = 0;

        // The App Review demo logins hold made-up records and a fake
        // Propertyware contact; looking them up would hit the real API.
        $accounts = ClientAccount::query()
            ->whereIn('role', [ClientRole::Owner, ClientRole::Resident])
            ->whereHas('user', fn ($user) => $user->where('is_demo', false)->whereNull('suspended_at'))
            ->with('user')
            ->get();

        foreach ($accounts as $account) {
            try {
                $workOrders = $maintenance->forClient($directory->scopeFor($account));
            } catch (PropertywareException $exception) {
                // Tried again on the next run; nothing is recorded for this account.
                Log::warning('Could not read work orders for status updates.', [
                    'client_account_id' => $account->id,
                    'reason' => $exception->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            $known = WorkOrderStatusNotice::query()
                ->where('client_account_id', $account->id)
                ->get()
                ->keyBy('work_order_id');

            foreach ($workOrders as $workOrder) {
                /** @var WorkOrder $workOrder */
                $last = $known->get($workOrder->id);

                if ($last === null) {
                    WorkOrderStatusNotice::create([
                        'client_account_id' => $account->id,
                        'work_order_id' => $workOrder->id,
                        'status' => $workOrder->status,
                    ]);

                    continue;
                }

                if ($last->status === $workOrder->status) {
                    continue;
                }

                $tell = in_array($workOrder->status, self::TOLD, strict: true);

                if ($tell) {
                    $account->user->notify(new WorkOrderUpdatedNotification($account, $workOrder));
                    $sent++;
                }

                $last->update([
                    'status' => $workOrder->status,
                    'notified_at' => $tell ? now() : $last->notified_at,
                ]);
            }
        }

        $this->info("Sent {$sent} work order update(s); could not read {$skipped} account(s).");

        return self::SUCCESS;
    }
}
