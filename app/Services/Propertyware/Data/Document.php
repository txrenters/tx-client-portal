<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * A file staff attached to a property, lease, or work order.
 */
class Document
{
    /**
     * The file types the portal shows in the browser. Anything else downloads,
     * so a file that can carry script (an SVG or HTML page, say) never runs on
     * the portal's own domain.
     */
    public const VIEWABLE_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
    ];

    /**
     * Propertyware's names for types that have a standard one. It calls most
     * photos "image/jpg", which no browser or MIME list knows, so they would
     * otherwise neither preview nor get a thumbnail.
     */
    private const TYPE_ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-png' => 'image/png',
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $fileName,
        public readonly ?string $fileType,
        public readonly ?string $description,
        public readonly string $entityType,
        public readonly ?int $entityId,
        public readonly bool $visibleToTenant,
        public readonly bool $visibleToOwner,
        public readonly ?Carbon $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        try {
            $createdAt = filled($record['createdDateTime'] ?? null)
                ? Carbon::parse($record['createdDateTime'])
                : null;
        } catch (InvalidFormatException) {
            $createdAt = null;
        }

        return new self(
            id: (string) ($record['id'] ?? ''),
            fileName: (string) ($record['fileName'] ?? 'Document'),
            fileType: self::standardType($record['fileType'] ?? null),
            description: filled($record['description'] ?? null) ? $record['description'] : null,
            entityType: (string) ($record['entityType'] ?? ''),
            entityId: isset($record['entityId']) ? (int) $record['entityId'] : null,
            visibleToTenant: (bool) ($record['publishToTenantPortal'] ?? false),
            visibleToOwner: (bool) ($record['publishToOwnerPortal'] ?? false),
            createdAt: $createdAt,
        );
    }

    /**
     * The file's type under its standard name, or null when Propertyware gave none.
     */
    private static function standardType(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $type = strtolower(trim($type));

        return self::TYPE_ALIASES[$type] ?? $type;
    }

    /**
     * Determine whether staff published this file to the client's portal.
     */
    public function isVisibleTo(ClientRole $role): bool
    {
        return $role === ClientRole::Owner ? $this->visibleToOwner : $this->visibleToTenant;
    }

    /**
     * Determine whether the browser can display this file in a viewer.
     */
    public function isViewable(): bool
    {
        return in_array(strtolower((string) $this->fileType), self::VIEWABLE_TYPES, true);
    }

    /**
     * Get the id of the work order this file is attached to, if any.
     */
    public function workOrderId(): ?string
    {
        return $this->entityType === 'WORK_ORDER' && $this->entityId !== null ? (string) $this->entityId : null;
    }

    /**
     * Get the file type group the portal filters by: images, pdf or other.
     */
    public function typeGroup(): string
    {
        return match (true) {
            str_starts_with((string) $this->fileType, 'image/') => 'images',
            $this->fileType === 'application/pdf' => 'pdf',
            default => 'other',
        };
    }

    /**
     * Get the client-facing label for what this file belongs to.
     */
    public function category(): string
    {
        return match ($this->entityType) {
            'LEASE' => 'Lease',
            'BUILDING' => 'Property',
            'PORTFOLIO' => 'Portfolio',
            'UNIT' => 'Unit',
            'WORK_ORDER' => 'Maintenance',
            'CONTACT' => 'Account',
            default => 'Other',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fileName' => $this->fileName,
            'fileType' => $this->fileType,
            'description' => $this->description,
            'category' => $this->category(),
            'workOrderId' => $this->workOrderId(),
            'viewable' => $this->isViewable(),
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
