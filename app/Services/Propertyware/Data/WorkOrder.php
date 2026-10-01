<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;
use App\Enums\WorkOrderStatus;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * A maintenance request, trimmed to what a client may see.
 */
class WorkOrder
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $priority,
        public readonly ?string $category,
        public readonly ?string $location,
        public readonly ?string $specificLocation,
        public readonly ?int $ageInDays,
        public readonly ?string $authorizedToEnter,
        public readonly ?string $source,
        public readonly ?string $requestedBy,
        public readonly ?string $managedBy,
        public readonly ?string $leaseName,
        public readonly ?string $contractNumber,
        public readonly ?string $requiredMaterials,
        public readonly ?string $maintenanceNotice,
        public readonly bool $approved,
        public readonly ?float $hourEstimate,
        /** @var array<int, array<string, string|null>> */
        public readonly array $vendors,
        public readonly ?float $ownerCost,
        public readonly ?float $actualCost,
        public readonly ?float $costEstimate,
        public readonly ?string $number,
        public readonly string $description,
        public readonly WorkOrderStatus $status,
        public readonly ?string $type,
        public readonly ?int $unitId,
        public readonly ?int $buildingId,
        public readonly ?int $portfolioId,
        public readonly bool $visibleToTenant,
        public readonly bool $visibleToOwner,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $updatedAt,
        public readonly ?Carbon $scheduledFor,
        public readonly ?Carbon $completedAt,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $approvedAt = null,
        public readonly ?Carbon $entryDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        return new self(
            id: (string) ($record['id'] ?? ''),
            priority: $record['priority'] ?? null,
            category: $record['category'] ?? null,
            location: $record['location'] ?? null,
            specificLocation: $record['specificLocation'] ?? null,
            ageInDays: isset($record['age']) ? (int) $record['age'] : null,
            authorizedToEnter: $record['authorizedToEnter'] ?? null,
            source: $record['source'] ?? null,
            requestedBy: $record['requestedBy'] ?? null,
            managedBy: $record['managedBy'] ?? null,
            leaseName: $record['lease'] ?? null,
            contractNumber: $record['contractNumber'] ?? null,
            requiredMaterials: $record['requiredMaterials'] ?? null,
            maintenanceNotice: $record['maintenanceNotice'] ?? null,
            approved: (bool) ($record['approved'] ?? false),
            hourEstimate: isset($record['hourEstimate']) ? (float) $record['hourEstimate'] : null,
            vendors: collect(is_array($record['assignedVendors'] ?? null) ? $record['assignedVendors'] : [])
                ->filter(fn ($vendor): bool => is_array($vendor))
                ->map(fn (array $vendor): array => [
                    'name' => $vendor['companyName'] ?? $vendor['name'] ?? null,
                    'type' => $vendor['type'] ?? null,
                    'phone' => $vendor['phone'] ?? null,
                    'email' => $vendor['email'] ?? null,
                ])
                ->filter(fn (array $vendor): bool => filled($vendor['name']))
                ->values()
                ->all(),
            ownerCost: isset($record['ownerCost']) ? (float) $record['ownerCost'] : null,
            actualCost: isset($record['actualCost']) ? (float) $record['actualCost'] : null,
            costEstimate: isset($record['costEstimate']) ? (float) $record['costEstimate'] : null,
            number: isset($record['number']) ? (string) $record['number'] : null,
            description: (string) ($record['description'] ?? ''),
            status: WorkOrderStatus::fromRecord($record),
            type: $record['type'] ?? null,
            unitId: isset($record['unitID']) ? (int) $record['unitID'] : null,
            buildingId: isset($record['buildingID']) ? (int) $record['buildingID'] : null,
            portfolioId: isset($record['portfolioID']) ? (int) $record['portfolioID'] : null,
            visibleToTenant: (bool) ($record['publishToTenantPortal'] ?? false),
            visibleToOwner: (bool) ($record['publishToOwnerPortal'] ?? false),
            createdAt: self::date($record['createdDateTime'] ?? null),
            updatedAt: self::date($record['lastModifiedDateTime'] ?? null),
            scheduledFor: self::date($record['scheduledEndDate'] ?? null),
            completedAt: self::date($record['completedDate'] ?? null),
            startedAt: self::date($record['startDate'] ?? null),
            approvedAt: self::date($record['approvedDate'] ?? null),
            entryDate: self::date($record['dateToEnter'] ?? null),
        );
    }

    /**
     * Shape the work order for the client portal.
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $propertyAddress = null): array
    {
        return [
            'id' => $this->id,
            'approved' => $this->approved,
            'priority' => $this->priority,
            'category' => $this->category,
            'buildingId' => $this->buildingId,
            'number' => $this->number,
            'title' => $this->title(),
            'description' => $this->description,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'type' => $this->type,
            'property' => $propertyAddress,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
            'scheduledFor' => $this->scheduledFor?->toIso8601String(),
            'completedAt' => $this->completedAt?->toIso8601String(),
            'startedAt' => $this->startedAt?->toIso8601String(),
            'entryDate' => $this->entryDate?->toIso8601String(),
        ];
    }

    /**
     * Determine whether the owner still has to approve this work order: it is
     * open or scheduled and Propertyware has no approval for it.
     */
    public function awaitsOwnerApproval(): bool
    {
        return ! $this->approved && in_array($this->status, [WorkOrderStatus::Open, WorkOrderStatus::Scheduled], true);
    }

    /**
     * Shape the work order for a detail page, including the event timeline.
     *
     * Costs are for owners only; tenants never see them.
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(?string $propertyAddress, ClientRole $viewer): array
    {
        $includeCosts = $viewer === ClientRole::Owner;

        return [
            ...$this->toArray($propertyAddress),
            'location' => $this->location,
            'specificLocation' => $this->specificLocation,
            'ageInDays' => $this->ageInDays,
            'authorizedToEnter' => $this->authorizedToEnter,
            'source' => $this->source,
            // Owners never see tenant names and tenants never see owner names, so the
            // reporter is never shared, and lease names (named after tenants) only go to tenants.
            'requestedBy' => null,
            'managedBy' => $this->managedBy,
            'leaseName' => $viewer === ClientRole::Resident ? $this->leaseName : null,
            'contractNumber' => $this->contractNumber,
            'requiredMaterials' => $this->requiredMaterials,
            'approved' => $this->approved,
            'vendors' => $this->vendorsFor($includeCosts),
            'ownerCost' => $includeCosts ? $this->ownerCost : null,
            'actualCost' => $includeCosts ? $this->actualCost : null,
            'costEstimate' => $includeCosts ? $this->costEstimate : null,
            'hourEstimate' => $includeCosts ? $this->hourEstimate : null,
            'maintenanceNotice' => $includeCosts ? $this->maintenanceNotice : null,
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * Get the assigned vendors, keeping their contact details for owners only.
     *
     * @return array<int, array<string, string|null>>
     */
    private function vendorsFor(bool $includeContactDetails): array
    {
        return collect($this->vendors)
            ->map(fn (array $vendor): array => $includeContactDetails ? $vendor : [
                'name' => $vendor['name'],
                'type' => $vendor['type'],
                'phone' => null,
                'email' => null,
            ])
            ->all();
    }

    /**
     * Build the client-facing history of the request, oldest first.
     *
     * @return array<int, array{label: string, at: string}>
     */
    public function timeline(): array
    {
        return collect([
            ['label' => 'Request received', 'date' => $this->createdAt],
            ['label' => 'Approved', 'date' => $this->approvedAt],
            ['label' => 'Work started', 'date' => $this->startedAt],
            ['label' => 'Scheduled', 'date' => $this->scheduledFor],
            ['label' => 'Completed', 'date' => $this->completedAt],
            ['label' => 'Last updated', 'date' => $this->updatedAt],
        ])
            ->filter(fn (array $event): bool => $event['date'] !== null)
            ->sortBy(fn (array $event): int => $event['date']->getTimestamp())
            ->map(fn (array $event): array => [
                'label' => $event['label'],
                'at' => $event['date']->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Get a short, single-line summary for lists and cards.
     */
    public function title(): string
    {
        $firstLine = trim(strtok($this->description, "\n") ?: '');

        if ($firstLine === '') {
            return $this->type ?? 'Maintenance request';
        }

        return mb_strimwidth($firstLine, 0, 80, '…');
    }

    /**
     * Parse a Propertyware timestamp, ignoring values it stores in odd formats.
     */
    private static function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
