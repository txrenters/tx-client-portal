<?php

namespace App\Services\Propertyware;

use App\Models\PropertywareApproval;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a history of work order approvals as Propertyware shows them.
 *
 * Propertyware overwrites a work order's approval and its comment in place,
 * whether staff change it there, tx-maintenance does, or the portal does. Each
 * run reads the work orders changed since the last one, reads their approval
 * over SOAP (REST has no approver or comment), and adds a row whenever it
 * differs from the last one kept. Rows are never edited.
 */
class ApprovalHistory
{
    /**
     * Cache key holding when the last complete run started.
     */
    public const SINCE = 'propertyware:approval-history:since';

    /**
     * Re-read this far before the last run, so a change saved while it ran is not missed.
     */
    private const OVERLAP_MINUTES = 10;

    public function __construct(
        private PropertywareClient $propertyware,
        private PropertywareSoap $soap,
    ) {}

    /**
     * Record approvals that changed since the last run, or since the given time.
     *
     * The start of the next run moves forward only when every work order was
     * read, so one Propertyware failure is retried rather than skipped.
     *
     * @return array{checked: int, recorded: int, failed: int}
     *
     * @throws PropertywareException
     */
    public function record(?CarbonInterface $since = null): array
    {
        $startedAt = now();
        $since ??= Carbon::parse(Cache::get(self::SINCE, $startedAt->copy()->subDay()->toIso8601String()));

        $changed = $this->propertyware->listAll('workorders', [
            'lastModifiedDateTimeStart' => $since->copy()->subMinutes(self::OVERLAP_MINUTES)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        $checked = 0;
        $recorded = 0;
        $failed = 0;

        foreach ($changed as $workOrder) {
            $number = (int) ($workOrder['number'] ?? 0);

            if ($number <= 0 || ! isset($workOrder['id'])) {
                continue;
            }

            try {
                $approval = $this->soap->workOrderApproval($number);
            } catch (PropertywareException $exception) {
                Log::warning('Could not read a work order\'s approval from Propertyware; it is read again next run.', [
                    'work_order_number' => $number,
                    'message' => $exception->getMessage(),
                ]);
                $failed++;

                continue;
            }

            $checked++;

            if ($approval !== null && $this->keep((string) $workOrder['id'], (string) $number, $approval, $workOrder['lastModifiedDateTime'] ?? null)) {
                $recorded++;
            }
        }

        if ($failed === 0) {
            Cache::forever(self::SINCE, $startedAt->toIso8601String());
        }

        return ['checked' => $checked, 'recorded' => $recorded, 'failed' => $failed];
    }

    /**
     * Add a row when the approval differs from the last one kept.
     *
     * A work order never approved and without a comment has nothing to keep.
     *
     * @param  array{approved: bool, approvedDate: ?string, approvedBy: ?string, approvedByEmail: ?string, comment: ?string}  $approval
     */
    private function keep(string $workOrderId, string $number, array $approval, ?string $modifiedAt): bool
    {
        $latest = PropertywareApproval::query()
            ->where('work_order_id', $workOrderId)
            ->latest('recorded_at')
            ->latest('id')
            ->first();

        if ($latest === null && ! $approval['approved'] && $approval['comment'] === null) {
            return false;
        }

        if ($latest !== null
            && $latest->approved === $approval['approved']
            && $latest->approved_date?->toDateString() === $approval['approvedDate']
            && $latest->approved_by === $approval['approvedBy']
            && $latest->approval_comment === $approval['comment']) {
            return false;
        }

        PropertywareApproval::create([
            'work_order_id' => $workOrderId,
            'work_order_number' => $number,
            'approved' => $approval['approved'],
            'approved_date' => $approval['approvedDate'],
            'approved_by' => $approval['approvedBy'],
            'approved_by_email' => $approval['approvedByEmail'],
            'approval_comment' => $approval['comment'],
            'work_order_modified_at' => $modifiedAt !== null ? Carbon::parse($modifiedAt) : null,
            'recorded_at' => now(),
        ]);

        return true;
    }
}
