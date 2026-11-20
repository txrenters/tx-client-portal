<?php

namespace App\Services\Notifications;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * What the bell counts, for the web portal and the app alike: unread replies,
 * work orders waiting for an owner, and stored notices such as lease
 * reminders. A section is null when its source could not be read.
 */
class NeedsAttention
{
    public function __construct(
        private ClientConversations $conversations,
        private PropertywareDirectory $directory,
        private WorkOrderApprovals $approvals,
    ) {}

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    public function messages(User $user, ?ClientAccount $account, int $limit): ?array
    {
        if ($account === null || ! $this->conversations->isAvailable()) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $this->conversations->unread($user, $account, $limit);
        } catch (ChatHubException) {
            return null;
        }
    }

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    public function approvals(?ClientAccount $owner, int $limit): ?array
    {
        if ($owner === null) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $this->approvals->summaryFor($this->directory->scopeFor($owner), $owner, $limit);
        } catch (PropertywareException) {
            return null;
        }
    }

    /**
     * The stored lease, work order and meeting notices that belong to one of
     * the user's accounts. A meeting reminder lists every owner account of
     * its login, so it shows under each.
     *
     * The account is matched with a JSON "contains" rather than `=`: its
     * binding is JSON text, so Postgres compares jsonb to jsonb instead of the
     * `->>` text to an integer parameter, and SQLite still matches the stored
     * integer. A user with an owner and a tenant account sees each notice
     * under its own account only.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public function notices(User $user, ClientAccount $account): MorphMany
    {
        return $user->notifications()
            ->whereIn('data->type', ['lease_expiring', 'work_order_update', 'meeting_reminder'])
            ->whereJsonContains('data->account_id', $account->id);
    }

    /**
     * A stored notice as both clients read it.
     *
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'],
            'role' => $data['role'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'],
            'message' => $data['message'],
            'building' => $data['building'] ?? null,
            'unit' => $data['unit'] ?? null,
            'buildingId' => $data['building_id'] ?? null,
            'startDate' => $data['start_date'] ?? null,
            'endDate' => $data['end_date'] ?? null,
            'renewalDeadline' => $data['renewal_deadline'] ?? null,
            'url' => $data['url'] ?? null,
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }
}
