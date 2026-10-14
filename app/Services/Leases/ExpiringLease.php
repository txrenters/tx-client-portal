<?php

namespace App\Services\Leases;

use Carbon\CarbonImmutable;

/**
 * A lease the 90-day reminder is about.
 *
 * It carries the property's labels and the ids of the people named on the
 * lease, and nothing else about them: no names, no lease name (it is built
 * from tenant surnames), no contact details. That way the owner's notice
 * cannot leak who lives there, whatever builds it.
 */
final class ExpiringLease
{
    /**
     * @param  array<int, string>  $tenantContactIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $portfolioId,
        public readonly int $buildingId,
        public readonly string $building,
        public readonly string $unit,
        public readonly ?CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly array $tenantContactIds,
    ) {}

    /** The last day renewal papers can be approved: the home is relisted 60 days out. */
    public function renewalDeadline(): CarbonImmutable
    {
        return $this->endDate->subDays(60);
    }

    public function daysLeft(CarbonImmutable $today): int
    {
        return (int) $today->startOfDay()->diffInDays($this->endDate->startOfDay(), false);
    }
}
