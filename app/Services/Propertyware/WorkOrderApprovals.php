<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WorkOrderApproval;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owners approve or decline work orders in the portal.
 *
 * Propertyware records its API login as the approver of anything approved
 * through the API, so every decision is kept here as the lasting record of
 * who decided, and the approver is also written into Propertyware's
 * approval comment, which survives later updates to the work order.
 */
class WorkOrderApprovals
{
    /**
     * Marks approvals made in test mode, which are never sent to Propertyware.
     */
    public const TEST_APPROVAL = 'Test approval; never sent to Propertyware.';

    /**
     * Marks a decision replaced by a later one before it reached Propertyware.
     */
    public const SUPERSEDED = 'Replaced by a later decision; not sent.';

    /**
     * Typographic punctuation, as phones type it, and the plain form Propertyware can store.
     *
     * @var array<string, string>
     */
    private const PLAIN_PUNCTUATION = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
    ];

    public function __construct(
        private MaintenanceSummary $maintenance,
        private PropertywareSoap $soap,
        private PropertywareDirectory $directory,
    ) {}

    /**
     * Get the owner's work orders still waiting on their approval, newest first.
     *
     * @return Collection<int, WorkOrder>
     *
     * @throws PropertywareException
     */
    public function awaitingFor(ClientScope $scope, ClientAccount $account): Collection
    {
        if ($scope->role !== ClientRole::Owner) {
            return collect();
        }

        $waiting = $this->maintenance->forClient($scope)->filter(fn (WorkOrder $workOrder): bool => $workOrder->awaitsOwnerApproval());
        $latest = $this->latest($account, $waiting->pluck('id')->all());

        return $waiting
            ->reject(fn (WorkOrder $workOrder): bool => $latest->get($workOrder->id)?->decision === 'approved')
            ->values();
    }

    /**
     * How many work orders wait on the owner, with the first few to show.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     *
     * @throws PropertywareException
     */
    public function summaryFor(ClientScope $scope, ClientAccount $account, int $limit = 6): array
    {
        $waiting = $this->awaitingFor($scope, $account);
        $first = $waiting->take($limit);
        $addresses = $this->directory->addressesForBuildings($first->pluck('buildingId')->filter()->unique()->values()->all());

        return [
            'count' => $waiting->count(),
            'items' => $first
                ->map(fn (WorkOrder $workOrder): array => [
                    ...$workOrder->toArray($addresses[$workOrder->buildingId] ?? null),
                    'awaitingApproval' => true,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The owner's approval state for one work order.
     *
     * @return array{canDecide: bool, awaiting: bool, decision: ?string, decidedAt: ?string, decidedBy: ?string, note: ?string, pendingSync: bool}
     */
    public function stateFor(ClientAccount $account, WorkOrder $workOrder): array
    {
        $latest = $this->latest($account, [$workOrder->id])->get($workOrder->id);
        $awaiting = $account->role === ClientRole::Owner && $workOrder->awaitsOwnerApproval();
        $testing = $account->role === ClientRole::Owner && config('services.propertyware.approval_test_mode');

        return [
            // Declining keeps the work order unapproved, so the owner may still change their mind.
            'canDecide' => $testing || ($awaiting && ($latest === null || $latest->decision === 'declined')),
            'awaiting' => $testing || ($awaiting && ($latest === null || $latest->decision === 'declined')),
            'decision' => $latest?->decision,
            'decidedAt' => $latest?->decided_at->toIso8601String(),
            'decidedBy' => $latest?->approver_name,
            'note' => $latest?->note,
            'pendingSync' => $latest?->decision === 'approved' && $latest->propertyware_synced_at === null,
        ];
    }

    /**
     * Flag the rows of a work order list that still wait on this owner.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function markAwaiting(ClientAccount $account, array $rows): array
    {
        if ($account->role !== ClientRole::Owner) {
            return $rows;
        }

        $latest = $this->latest($account, array_map(fn (array $row): string => (string) $row['id'], $rows));

        return array_map(fn (array $row): array => [
            ...$row,
            'awaitingApproval' => ! ($row['approved'] ?? false)
                && in_array($row['status'] ?? null, ['open', 'scheduled'], true)
                && ($latest->get((string) $row['id'])->decision ?? 'declined') === 'declined',
        ], $rows);
    }

    /**
     * Get the latest portal decision for each of these work orders.
     *
     * @param  array<int, string>  $workOrderIds
     * @return Collection<string, WorkOrderApproval>
     */
    public function latest(ClientAccount $account, array $workOrderIds): Collection
    {
        return WorkOrderApproval::query()
            ->where('client_account_id', $account->id)
            ->whereIn('work_order_id', $workOrderIds)
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get()
            ->keyBy('work_order_id');
    }

    /**
     * Record the owner's decision, and approve in Propertyware when they approve.
     *
     * @param  array{ip?: ?string, userAgent?: ?string}  $context
     *
     * @throws PropertywareException
     * @throws ValidationException
     */
    public function decide(ClientScope $scope, User $user, ClientAccount $account, string $workOrderId, string $decision, ?string $note, array $context = []): WorkOrderApproval
    {
        $workOrder = $this->maintenance->forClient($scope)->first(fn (WorkOrder $candidate): bool => $candidate->id === $workOrderId);

        if ($scope->role !== ClientRole::Owner || $workOrder === null || ! $this->stateFor($account, $workOrder)['canDecide']) {
            throw ValidationException::withMessages([
                'decision' => __('This work order is not waiting for your approval.'),
            ]);
        }

        $approval = WorkOrderApproval::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'client_account_id' => $account->id,
            'propertyware_contact_id' => $scope->contactId,
            'work_order_id' => $workOrder->id,
            'work_order_number' => $workOrder->number,
            'decision' => $decision,
            'note' => filled($note) ? trim($note) : null,
            'approver_name' => $user->name,
            'ip_address' => $context['ip'] ?? null,
            'user_agent' => $context['userAgent'] ?? null,
            'decided_at' => now(),
        ]);

        $this->sendToPropertyware($approval);

        return $approval->refresh();
    }

    /**
     * Propertyware's approval comment: the same fixed line tx-maintenance
     * writes, naming the owner, since Propertyware credits the API login as
     * the approver, followed by the owner's own comment when they left one.
     * The full record of who decided stays in the portal.
     *
     * Propertyware stores the comment as a question mark wherever a phone's
     * curly quote or dash was ("it?s"), so those go as their plain forms.
     */
    public function approvalComment(WorkOrderApproval $approval): string
    {
        $verb = $approval->decision === 'approved' ? 'approve' : 'decline';

        $line = "I {$verb} this work order. - ".($approval->approver_name !== '' ? $approval->approver_name : 'the owner')
            .', '.Carbon::parse($approval->decided_at)->setTimezone(config('app.display_timezone'))->format('m/d/Y');

        return strtr(filled($approval->note) ? $line.'. Comment: '.$approval->note : $line, self::PLAIN_PUNCTUATION);
    }

    /**
     * Record the owner's decision on the work order in Propertyware, keeping it here if that fails.
     *
     * A decline leaves the work order unapproved and puts the owner's reason in
     * the approval comment, so staff see it on the work order. When pushing is
     * turned off or Propertyware refuses, the decision stays recorded here and
     * is sent again by the approvals:push command.
     */
    public function sendToPropertyware(WorkOrderApproval $approval): void
    {
        if (config('services.propertyware.approval_test_mode')) {
            $approval->update(['sync_error' => self::TEST_APPROVAL]);

            return;
        }

        if (! config('services.propertyware.approval_push')) {
            $approval->update(['sync_error' => 'Sending approvals to Propertyware is turned off.']);

            return;
        }

        try {
            $this->soap->approveWorkOrder(
                (int) $approval->work_order_id,
                // An xsd:dateTime: Propertyware accepts a bare date and then
                // drops it, leaving the work order approved with no date.
                Carbon::parse($approval->decided_at)->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i:s'),
                $this->approvalComment($approval),
                $approval->decision === 'approved',
            );
        } catch (PropertywareException $exception) {
            Log::error('Could not send the owner\'s decision to Propertyware; it is kept and will be sent again.', [
                'work_order_id' => $approval->work_order_id,
                'message' => $exception->getMessage(),
            ]);
            $approval->update(['sync_error' => $exception->getMessage()]);

            return;
        }

        $approval->update(['propertyware_synced_at' => now(), 'sync_error' => null]);
    }

    /**
     * Send decisions that have not reached Propertyware yet.
     *
     * Only the owner's latest decision on a work order is sent; an older one
     * still waiting is marked superseded, so a late retry of a decline can
     * never undo the approval that followed it.
     *
     * @return array{sent: int, failed: int}
     */
    public function pushPending(): array
    {
        $sent = 0;
        $failed = 0;

        WorkOrderApproval::query()
            ->whereNull('propertyware_synced_at')
            ->where(fn ($query) => $query->whereNull('sync_error')->orWhereNotIn('sync_error', [self::TEST_APPROVAL, self::SUPERSEDED]))
            // The App Review logins' approvals are for made-up work orders.
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->orderBy('decided_at')
            ->each(function (WorkOrderApproval $approval) use (&$sent, &$failed): void {
                $isLatest = ! WorkOrderApproval::query()
                    ->where('client_account_id', $approval->client_account_id)
                    ->where('work_order_id', $approval->work_order_id)
                    ->where('id', '>', $approval->id)
                    ->exists();

                if (! $isLatest) {
                    $approval->update(['sync_error' => self::SUPERSEDED]);

                    return;
                }

                $this->sendToPropertyware($approval);
                $approval->propertyware_synced_at !== null ? $sent++ : $failed++;
            });

        return ['sent' => $sent, 'failed' => $failed];
    }
}
