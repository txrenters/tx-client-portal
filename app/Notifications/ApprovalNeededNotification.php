<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * A work order is waiting for the owner's approval. Push only: the bell and
 * the Work Orders tab already list what is waiting.
 *
 * `type` and `workOrderId` are what the app reads to open the work order, and
 * `accountId` lets it switch to the portfolio it belongs to first.
 */
class ApprovalNeededNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $workOrder  one item from WorkOrderApprovals::summaryFor
     */
    public function __construct(public int $accountId, public array $workOrder) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoChannel::class];
    }

    public function toExpo(object $notifiable): PushMessage
    {
        $number = filled($this->workOrder['number'] ?? null) ? '#'.$this->workOrder['number'] : null;

        return new PushMessage(
            title: 'Approval needed',
            body: implode(' · ', array_filter([$number, $this->workOrder['category'] ?? null, $this->workOrder['property'] ?? null]))
                ?: (string) ($this->workOrder['title'] ?? 'A work order is waiting for you.'),
            data: [
                'type' => 'work_order_approval',
                'workOrderId' => (string) $this->workOrder['id'],
                'accountId' => $this->accountId,
                'url' => route('owner.maintenance.show', $this->workOrder['id']),
            ],
            channelId: 'approvals',
        );
    }
}
