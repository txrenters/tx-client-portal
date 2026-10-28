<?php

namespace App\Notifications;

use App\Enums\ClientRole;
use App\Enums\WorkOrderStatus;
use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Propertyware\Data\WorkOrder;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * A client's work order was scheduled, completed or canceled. Always kept in
 * their notification list; pushed to their phones unless they turned work
 * order updates off.
 *
 * Sent straight from the half-hourly command, not queued: the command records
 * the new status only after this returns, so a failure is tried again.
 */
class WorkOrderUpdatedNotification extends Notification
{
    /**
     * @var array<string, mixed>
     */
    public array $notice;

    public function __construct(ClientAccount $account, WorkOrder $workOrder)
    {
        $number = filled($workOrder->number) ? ' #'.$workOrder->number : '';
        $owner = $account->role === ClientRole::Owner;

        $title = match ($workOrder->status) {
            WorkOrderStatus::Scheduled => "Work order{$number} is scheduled",
            WorkOrderStatus::Completed => "Work order{$number} is complete",
            WorkOrderStatus::Canceled => "Work order{$number} was canceled",
            WorkOrderStatus::Open => "Work order{$number} is open",
        };

        $when = match ($workOrder->status) {
            WorkOrderStatus::Scheduled => $workOrder->scheduledFor === null ? null : 'Scheduled for '.$workOrder->scheduledFor->format('M j, Y').'.',
            WorkOrderStatus::Completed => $workOrder->completedAt === null ? null : 'Completed '.$workOrder->completedAt->format('M j, Y').'.',
            default => null,
        };

        $this->notice = [
            'type' => 'work_order_update',
            'role' => $account->role->value,
            'account_id' => $account->id,
            'work_order_id' => $workOrder->id,
            'status' => $workOrder->status->value,
            'title' => $title,
            'body' => $workOrder->title(),
            'message' => implode("\n", array_filter([$workOrder->title(), $when])),
            'url' => route($owner ? 'owner.maintenance.show' : 'resident.maintenance.show', $workOrder->id),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable instanceof User && $notifiable->wantsNotification('work_order_updates_push');

        return $push ? ['database', ExpoChannel::class] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->notice;
    }

    /**
     * `workOrderId` opens the work order in the app; `accountId` switches to
     * the account it belongs to first.
     */
    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->notice['title'],
            body: $this->notice['body'],
            data: [
                'type' => 'work_order_update',
                'workOrderId' => $this->notice['work_order_id'],
                'accountId' => $this->notice['account_id'],
                'notificationId' => $this->id,
                'url' => $this->notice['url'],
            ],
        );
    }
}
