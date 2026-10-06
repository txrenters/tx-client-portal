<?php

namespace App\Services\Leases;

use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;

/**
 * Leases that end inside a window, as Propertyware has them today.
 */
class ExpiringLeases
{
    /** Only these lease contacts are tenants worth telling; guarantors are not. */
    private const TENANT_ROLES = ['Primary', 'Secondary', 'Other'];

    public function __construct(private PropertywareClient $propertyware) {}

    /**
     * Active leases ending on or between the two dates. A tenant who has given
     * notice, or a lease already terminated, gets no renewal prompt.
     *
     * @return array<int, ExpiringLease>
     *
     * @throws PropertywareException
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $leases = collect($this->propertyware->listAll('leases', [
            'endDateStart' => $from->toDateString(),
            'endDateEnd' => $to->toDateString(),
        ]))->filter(fn (array $lease): bool => ($lease['status'] ?? null) === 'Active' && filled($lease['endDate'] ?? null))->values();

        if ($leases->isEmpty()) {
            return [];
        }

        $paths = $leases->flatMap(fn (array $lease): array => array_filter([
            'building:'.(int) $lease['buildingID'] => "buildings/{$lease['buildingID']}",
            'unit:'.(int) ($lease['unitID'] ?? 0) => empty($lease['unitID']) ? null : "units/{$lease['unitID']}",
        ]))->all();

        $records = $this->propertyware->getMany($paths);

        return $leases->map(function (array $lease) use ($records): ExpiringLease {
            $building = self::buildingLabel($records['building:'.(int) $lease['buildingID']] ?? [], (int) $lease['buildingID']);
            $unit = $records['unit:'.(int) ($lease['unitID'] ?? 0)] ?? [];

            return new ExpiringLease(
                id: (int) $lease['id'],
                portfolioId: (int) ($lease['portfolioID'] ?? 0),
                buildingId: (int) $lease['buildingID'],
                building: $building,
                unit: filled($unit['name'] ?? null) ? trim((string) $unit['name']) : $building,
                startDate: filled($lease['startDate'] ?? null) ? CarbonImmutable::parse($lease['startDate']) : null,
                endDate: CarbonImmutable::parse($lease['endDate']),
                tenantContactIds: array_values(array_map(
                    fn (array $contact): string => (string) $contact['id'],
                    array_filter(
                        $lease['contacts'] ?? [],
                        fn (mixed $contact): bool => is_array($contact)
                            && ($contact['namedOnLease'] ?? false) === true
                            && in_array($contact['role'] ?? null, self::TENANT_ROLES, strict: true)
                            && isset($contact['id']),
                    ),
                )),
            );
        })->all();
    }

    /**
     * The name staff gave the building, trimmed the way the portal shows it
     * elsewhere, or its street address when it has none.
     *
     * @param  array<string, mixed>  $record
     */
    private static function buildingLabel(array $record, int $id): string
    {
        $name = trim((string) ($record['name'] ?? ''), " \t\n\r,;-");

        if ($name !== '') {
            return $name;
        }

        $address = is_array($record['address'] ?? null) ? trim((string) ($record['address']['address'] ?? '')) : '';

        return $address !== '' ? $address : "Property {$id}";
    }
}
