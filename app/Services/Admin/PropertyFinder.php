<?php

namespace App\Services\Admin;

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Find a property by its address and see who is attached to it — the owners
 * on its portfolio and the tenants on its current leases — and whether each
 * of them is on the portal yet.
 *
 * Answers "my tenant at 12 Elm says they can't see their lease" in one
 * search. Every read is cached briefly, like the rest of the Propertyware
 * lookups, and the number of properties opened per search is capped so a
 * vague search does not fan out into hundreds of API calls.
 */
class PropertyFinder
{
    /** Properties opened up per search; the rest are only counted. */
    public const MAX_RESULTS = 10;

    private const CACHE_SECONDS = 300;

    public function __construct(
        private PropertywareDirectory $directory,
        private PropertywareClient $propertyware,
        private InvitationDesk $invitations,
    ) {}

    /**
     * Properties whose address or name contains the search.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int}
     *
     * @throws PropertywareException
     */
    public function search(string $term): array
    {
        $needle = mb_strtolower(trim($term));

        $matches = collect($this->directory->buildings())
            ->filter(function (array $building) use ($needle): bool {
                $property = Property::fromArray($building);

                return str_contains(mb_strtolower($property->fullAddress().' '.($property->name ?? '')), $needle);
            })
            ->values();

        $shown = $matches->take(self::MAX_RESULTS);
        $units = collect($this->directory->units())->groupBy(fn (array $unit): int => (int) ($unit['buildingID'] ?? 0));

        $leaseIds = $shown
            ->flatMap(fn (array $building): array => [
                $building['leaseID'] ?? null,
                ...$units->get((int) $building['id'], collect())->pluck('leaseID')->all(),
            ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $portfolioIds = $shown->pluck('portfolioID')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $leases = $this->records('leases', $leaseIds);
        $portfolios = $this->records('portfolios', $portfolioIds);

        return [
            'total' => $matches->count(),
            'results' => $shown->map(function (array $building) use ($units, $leases, $portfolios): array {
                $property = Property::fromArray($building);
                $buildingLeases = collect([$building['leaseID'] ?? null, ...$units->get((int) $building['id'], collect())->pluck('leaseID')->all()])
                    ->filter()->map(fn ($id): int => (int) $id)->unique();

                return [
                    'id' => $property->id,
                    'address' => $property->fullAddress(),
                    'name' => $property->name,
                    'occupied' => $buildingLeases->isNotEmpty(),
                    'owners' => $this->people(
                        $portfolios->get((int) ($building['portfolioID'] ?? 0))['owners'] ?? [],
                        ClientRole::Owner,
                    ),
                    'residents' => $this->people(
                        $buildingLeases->flatMap(fn (int $id): array => $leases->get($id)['contacts'] ?? [])->all(),
                        ClientRole::Resident,
                    ),
                ];
            })->all(),
        ];
    }

    /**
     * The people on a portfolio or lease with their portal status.
     *
     * @param  array<int, mixed>  $records
     * @return array<int, array<string, mixed>>
     */
    private function people(array $records, ClientRole $role): array
    {
        return collect($records)
            ->filter(fn (mixed $record): bool => is_array($record) && is_numeric($record['id'] ?? null))
            ->unique('id')
            ->map(fn (array $record): array => [
                'contactId' => (string) $record['id'],
                'role' => $role->value,
                'name' => trim(($record['firstName'] ?? '').' '.($record['lastName'] ?? '')) ?: (string) ($record['name'] ?? 'Unnamed'),
                'email' => filled($record['email'] ?? null) ? (string) $record['email'] : null,
                ...$this->invitations->portalStatus($role, (string) $record['id']),
            ])
            ->values()
            ->all();
    }

    /**
     * Read records by id through the cache, fetching the missing ones at once.
     *
     * Whatever comes back from the cache is only known to be an array, so the
     * records are typed no tighter than that.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws PropertywareException
     */
    private function records(string $path, Collection $ids): Collection
    {
        $cached = $ids->mapWithKeys(fn (int $id): array => [$id => Cache::get("admin:finder:{$path}:{$id}")])
            ->filter(fn (mixed $record): bool => is_array($record));

        $missing = $ids->reject(fn (int $id): bool => $cached->has($id))->values();

        if ($missing->isNotEmpty()) {
            $fetched = $this->propertyware->getMany($missing->mapWithKeys(fn (int $id): array => [$id => "{$path}/{$id}"])->all());

            foreach ($fetched as $id => $record) {
                Cache::put("admin:finder:{$path}:{$id}", $record, self::CACHE_SECONDS);
                $cached->put((int) $id, $record);
            }
        }

        return $cached;
    }
}
