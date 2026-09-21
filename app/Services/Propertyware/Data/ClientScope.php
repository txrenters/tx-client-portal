<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;

/**
 * The Propertyware records a signed-in client may see.
 *
 * Every client-facing query is filtered through this scope, so a client can
 * never reach another client's property, unit, or work order by guessing an id.
 */
class ClientScope
{
    /**
     * @param  array<int, int>  $leaseIds
     * @param  array<int, int>  $portfolioIds
     * @param  array<int, int>  $unitIds
     * @param  array<int, int>  $buildingIds
     */
    public function __construct(
        public readonly ClientRole $role,
        public readonly string $contactId,
        public readonly array $leaseIds = [],
        public readonly array $portfolioIds = [],
        public readonly array $unitIds = [],
        public readonly array $buildingIds = [],
    ) {}

    /**
     * Rebuild a scope from its cached array form.
     *
     * @param  array{role: string, contactId: string, leaseIds: array<int, int>, portfolioIds: array<int, int>, unitIds: array<int, int>, buildingIds: array<int, int>}  $scope
     */
    public static function fromArray(array $scope): self
    {
        return new self(
            role: ClientRole::from($scope['role']),
            contactId: $scope['contactId'],
            leaseIds: $scope['leaseIds'],
            portfolioIds: $scope['portfolioIds'],
            unitIds: $scope['unitIds'],
            buildingIds: $scope['buildingIds'],
        );
    }

    /**
     * Get the scope as plain values, so it can be cached.
     *
     * @return array{role: string, contactId: string, leaseIds: array<int, int>, portfolioIds: array<int, int>, unitIds: array<int, int>, buildingIds: array<int, int>}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'contactId' => $this->contactId,
            'leaseIds' => $this->leaseIds,
            'portfolioIds' => $this->portfolioIds,
            'unitIds' => $this->unitIds,
            'buildingIds' => $this->buildingIds,
        ];
    }

    public function allowsUnit(int|string|null $unitId): bool
    {
        return $unitId !== null && in_array((int) $unitId, $this->unitIds, strict: true);
    }

    public function allowsBuilding(int|string|null $buildingId): bool
    {
        return $buildingId !== null && in_array((int) $buildingId, $this->buildingIds, strict: true);
    }

    public function allowsPortfolio(int|string|null $portfolioId): bool
    {
        return $portfolioId !== null && in_array((int) $portfolioId, $this->portfolioIds, strict: true);
    }

    /**
     * Determine whether the client may see the given work order record.
     *
     * Tenants see work orders for the units on their lease. Owners see work
     * orders belonging to the portfolios they own.
     *
     * @param  array<string, mixed>  $workOrder
     */
    public function allowsWorkOrder(array $workOrder): bool
    {
        if ($this->role === ClientRole::Owner) {
            return $this->allowsPortfolio($workOrder['portfolioID'] ?? null);
        }

        return $this->allowsUnit($workOrder['unitID'] ?? null)
            || $this->allowsBuilding($workOrder['buildingID'] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->unitIds === [] && $this->portfolioIds === [] && $this->buildingIds === [];
    }
}
