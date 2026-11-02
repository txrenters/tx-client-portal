<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Contact;
use App\Services\Propertyware\Data\ContactDetails;
use App\Services\Propertyware\Data\Lease;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\Data\PropertyDetails;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which Propertyware records belong to a signed-in client.
 *
 * Propertyware ignores query filters, so lookups are filtered here and the
 * responses are cached briefly to keep page loads off the API. Single-family
 * homes carry the lease on the building, while multi-unit properties carry it
 * on the unit, so both collections are matched.
 */
class PropertywareDirectory
{
    /**
     * Above this many portfolios it is cheaper to sweep the whole account once
     * and filter here than to ask Propertyware per portfolio. A management
     * company with 94 portfolios needs ~44s of per-portfolio calls against
     * ~2s for one sweep.
     */
    public const BULK_FETCH_THRESHOLD = 6;

    public function __construct(
        private PropertywareClient $propertyware,
        private int $cacheSeconds = 300,
    ) {}

    /**
     * Look up the contact a client login represents.
     *
     * @throws PropertywareException
     */
    public function findContact(string $contactId): ?Contact
    {
        $record = Cache::remember(
            "propertyware:contact:{$contactId}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->get("contacts/{$contactId}"),
        );

        if (! isset($record['id'])) {
            return null;
        }

        return Contact::fromArray($record);
    }

    /**
     * Get the client's own contact details on file, grouped for display.
     *
     * @return array<int, array{title: string, items: array<int, array{label: string, value: string}>}>
     *
     * @throws PropertywareException
     */
    public function contactDetailsFor(ClientScope $scope): array
    {
        if ($this->findContact($scope->contactId) === null) {
            return [];
        }

        return ContactDetails::for(Cache::get("propertyware:contact:{$scope->contactId}", []), $scope->role);
    }

    /**
     * Build the set of Propertyware records the client account may see.
     *
     * @throws PropertywareException
     */
    public function scopeFor(ClientAccount $account): ClientScope
    {
        $scope = Cache::remember(
            "propertyware:scope:{$account->role->value}:{$account->propertyware_id}",
            $this->cacheSeconds,
            fn (): array => $this->buildScope($account)->toArray(),
        );

        return ClientScope::fromArray($scope);
    }

    /**
     * Work out which records the client account may see from Propertyware.
     *
     * @throws PropertywareException
     */
    private function buildScope(ClientAccount $account): ClientScope
    {
        $contact = $this->findContact($account->propertyware_id);

        if ($contact === null || $contact->role !== $account->role) {
            return new ClientScope(role: $account->role, contactId: $account->propertyware_id);
        }

        $belongsToClient = fn (array $record): bool => $account->role === ClientRole::Owner
            ? in_array((int) ($record['portfolioID'] ?? 0), $contact->portfolioIds, strict: true)
            : in_array((int) ($record['leaseID'] ?? 0), $contact->leaseIds, strict: true);

        $units = collect($this->units())->filter($belongsToClient);

        // An owner's buildings can be fetched by portfolio; a tenant's lease is
        // only discoverable by matching the lease id carried on each building.
        $buildings = $account->role === ClientRole::Owner
            ? collect($this->buildingsInPortfolios($contact->portfolioIds))
            : collect($this->buildings())->filter($belongsToClient);

        $buildingIds = $buildings->pluck('id')
            ->merge($units->pluck('buildingID'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new ClientScope(
            role: $account->role,
            contactId: $contact->id,
            leaseIds: $contact->leaseIds,
            portfolioIds: $contact->portfolioIds,
            unitIds: $units->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            buildingIds: $buildingIds,
        );
    }

    /**
     * Get the properties the client rents or owns.
     *
     * @return array<int, Property>
     *
     * @throws PropertywareException
     */
    public function propertiesFor(ClientScope $scope): array
    {
        $unitNames = collect($this->units())
            ->filter(fn (array $unit): bool => $scope->allowsUnit($unit['id'] ?? null))
            ->mapWithKeys(fn (array $unit): array => [(int) ($unit['buildingID'] ?? 0) => $unit['name'] ?? null]);

        return collect($this->buildingsFor($scope))
            ->filter(fn (array $building): bool => $scope->allowsBuilding($building['id'] ?? null))
            ->map(fn (array $building): Property => Property::fromArray(
                $this->shareableBuilding($building, $scope),
                $unitNames[(int) ($building['id'] ?? 0)] ?? null,
            ))
            ->values()
            ->all();
    }

    /**
     * Get one of the client's properties, or null when it isn't theirs.
     *
     * @throws PropertywareException
     */
    public function propertyFor(ClientScope $scope, int $buildingId): ?Property
    {
        if (! $scope->allowsBuilding($buildingId)) {
            return null;
        }

        $building = $this->findBuilding($buildingId);

        return $building === null ? null : Property::fromArray($this->shareableBuilding($building, $scope));
    }

    /**
     * Map the client's units and current leases to the property they belong to,
     * so their files can be listed with that property.
     *
     * @return array{units: array<int, int>, leases: array<int, int>}
     *
     * @throws PropertywareException
     */
    public function propertyLocations(ClientScope $scope): array
    {
        return [
            'units' => collect($this->units())
                ->filter(fn (array $unit): bool => $scope->allowsUnit($unit['id'] ?? null) && filled($unit['buildingID'] ?? null))
                ->mapWithKeys(fn (array $unit): array => [(int) $unit['id'] => (int) $unit['buildingID']])
                ->all(),
            'leases' => collect($this->buildingsFor($scope))
                ->filter(fn (array $building): bool => $scope->allowsBuilding($building['id'] ?? null) && filled($building['leaseID'] ?? null))
                ->mapWithKeys(fn (array $building): array => [(int) $building['leaseID'] => (int) $building['id']])
                ->all(),
        ];
    }

    /**
     * Get the extra details shown on a property page, trimmed for this client.
     *
     * @return array{sections: array<int, array{title: string, items: array<int, array{label: string, value: string}>}>, amenities: array<int, string>, managers: array<int, array{name: string, email: ?string}>}|null
     *
     * @throws PropertywareException
     */
    public function detailsFor(ClientScope $scope, int $buildingId): ?array
    {
        if (! $scope->allowsBuilding($buildingId)) {
            return null;
        }

        $building = $this->findBuilding($buildingId);

        return $building === null ? null : PropertyDetails::for($building, $scope->role);
    }

    /**
     * Drop what this client may not see from a building record.
     *
     * Staff often put the owner's name in a building's name, and tenants must
     * never see owner names.
     *
     * @param  array<string, mixed>  $building
     * @return array<string, mixed>
     */
    private function shareableBuilding(array $building, ClientScope $scope): array
    {
        if ($scope->role === ClientRole::Resident) {
            unset($building['name']);
        }

        return $building;
    }

    /**
     * Get one page of the client's properties, sorted by address, after filtering.
     *
     * "4" bedrooms means four or more.
     *
     * @param  array{search?: ?string, occupancy?: ?string, city?: ?string, bedrooms?: ?string}  $filters
     * @return array{total: int, counts: array{occupied: int, vacant: int}, cities: array<int, string>, results: array<string, mixed>}
     *
     * @throws PropertywareException
     */
    public function paginateProperties(ClientScope $scope, array $filters, int $page = 1, int $perPage = 12, string $path = '/'): array
    {
        $all = collect($this->propertiesFor($scope))->sortBy(fn (Property $property): string => mb_strtolower($property->fullAddress()))->values();
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $bedrooms = filled($filters['bedrooms'] ?? null) ? (int) $filters['bedrooms'] : null;

        $matching = $all
            ->filter(fn (Property $property): bool => blank($filters['occupancy'] ?? null) || $property->occupied === ($filters['occupancy'] === 'occupied'))
            ->filter(fn (Property $property): bool => blank($filters['city'] ?? null) || $property->city === $filters['city'])
            ->filter(fn (Property $property): bool => $bedrooms === null || ($bedrooms >= 4 ? $property->bedrooms >= 4 : $property->bedrooms === $bedrooms))
            ->filter(fn (Property $property): bool => $search === '' || collect([$property->fullAddress(), $property->name, $property->unitName, $property->neighborhood, $property->postalCode])
                ->filter()
                ->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $search)))
            ->values();

        $onPage = $matching->forPage($page, $perPage)->values();
        // An owner sees each property's rent and when its lease ends. Only the
        // leases on this page are read, all at once.
        $leases = $scope->role === ClientRole::Owner
            ? $this->findLeases($onPage->pluck('leaseId')->filter()->all())
            : [];

        $paginator = new LengthAwarePaginator(
            $onPage->map(fn (Property $property): array => [
                ...$property->toArray(),
                'lease' => $property->leaseId !== null && isset($leases[$property->leaseId])
                    ? [
                        'rent' => $leases[$property->leaseId]->rent,
                        'endDate' => $leases[$property->leaseId]->endDate,
                        'moveOutDate' => $leases[$property->leaseId]->moveOutDate,
                        'endsSoon' => $leases[$property->leaseId]->endsSoon(),
                    ]
                    : null,
            ])->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => array_filter([...$filters, 'page' => null], filled(...))],
        );

        return [
            'total' => $all->count(),
            'counts' => [
                'occupied' => $all->where('occupied', true)->count(),
                'vacant' => $all->where('occupied', false)->count(),
            ],
            'cities' => $all->pluck('city')->filter()->unique()->sort()->values()->all(),
            'results' => $paginator->toArray(),
        ];
    }

    /**
     * Keep the contact's named portfolios, including those without buildings.
     *
     * @return array<int, array{id: int, name: string, properties: array<int, array<string, mixed>>}>
     */
    public function portfoliosFor(ClientScope $scope): array
    {
        if ($scope->role !== ClientRole::Owner) {
            return [];
        }

        $contact = $this->findContact($scope->contactId);

        if ($contact === null || $contact->role !== ClientRole::Owner) {
            return [];
        }

        $buildings = collect($this->buildingsFor($scope))
            ->filter(fn (array $building): bool => $scope->allowsBuilding($building['id'] ?? null))
            ->groupBy('portfolioID');

        return collect($contact->portfolios)
            ->filter(fn (array $portfolio): bool => $scope->allowsPortfolio($portfolio['id']))
            ->map(fn (array $portfolio): array => [
                ...$portfolio,
                'properties' => collect($buildings->get($portfolio['id'], []))
                    ->map(fn (array $building): array => Property::fromArray($building)->toArray())
                    ->values()->all(),
            ])->values()->all();
    }

    /**
     * One portfolio's own properties, named the way the owner knows them: the
     * building's name when Propertyware holds one, its address when not.
     *
     * Only the owner's own buildings, and only ones in this portfolio, so an id
     * checked against these keys can never reach anyone else's.
     *
     * @return array<int, string>
     *
     * @throws PropertywareException
     */
    public function propertyNamesInPortfolio(ClientScope $scope, int $portfolioId): array
    {
        $inPortfolio = collect($this->buildingsInPortfolios([$portfolioId]))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return collect($this->propertiesFor($scope))
            ->filter(fn (Property $property): bool => in_array($property->id, $inPortfolio, strict: true))
            ->mapWithKeys(fn (Property $property): array => [$property->id => $property->name ?: $property->address])
            ->all();
    }

    /**
     * An owner's portfolios for a picker, each named with the property it holds.
     *
     * The buildings are read by id, which is one cached request apiece and runs
     * several at a time, rather than asking Propertyware for every portfolio's
     * buildings one after another.
     *
     * @return array<int, array{id: int, name: string, properties: array<int, string>|null}>
     *
     * @throws PropertywareException
     */
    public function portfolioOptionsFor(ClientScope $scope): array
    {
        if ($scope->role !== ClientRole::Owner) {
            return [];
        }

        $contact = $this->findContact($scope->contactId);

        if ($contact === null || $contact->role !== ClientRole::Owner) {
            return [];
        }

        $portfolios = collect($contact->portfolios)
            ->filter(fn (array $portfolio): bool => $scope->allowsPortfolio($portfolio['id']))
            ->values();

        // The owner's own buildings, read by id and cached one by one, which is
        // far cheaper than asking for each portfolio's buildings in turn.
        $addresses = collect($this->findBuildings($scope->buildingIds))
            ->filter(fn (array $building): bool => $scope->allowsBuilding($building['id'] ?? null))
            ->groupBy(fn (array $building): int => (int) ($building['portfolioID'] ?? 0))
            ->map(fn ($group): array => $group
                ->map(fn (array $building): string => Property::fromArray($building)->address)
                ->filter()
                ->values()
                ->all());

        return $portfolios
            ->map(fn (array $portfolio): array => [
                'id' => (int) $portfolio['id'],
                'name' => (string) $portfolio['name'],
                // Null when we did not read them: an owner with ninety
                // portfolios is not told each one has no property.
                'properties' => $addresses->isEmpty() ? null : $addresses->get($portfolio['id'], []),
            ])
            ->all();
    }

    /**
     * Get one-line addresses keyed by building id, for labelling work orders.
     *
     * Vacant buildings are missing from Propertyware's listings but can still
     * be read by id, so each building is fetched individually.
     *
     * @param  array<int, int>  $buildingIds
     * @return array<int, string>
     *
     * @throws PropertywareException
     */
    public function addressesForBuildings(array $buildingIds): array
    {
        return collect($this->findBuildings($buildingIds))
            ->map(fn (array $building): string => Property::fromArray($building)->fullAddress())
            ->filter()
            ->all();
    }

    /**
     * Count how many of the client's properties are occupied.
     *
     * A building carries the id of its current lease when someone lives there.
     *
     * @return array{total: int, occupied: int, vacant: int}
     *
     * @throws PropertywareException
     */
    public function occupancyFor(ClientScope $scope): array
    {
        $listedBuildings = collect($this->buildingsFor($scope))
            ->filter(fn (array $building): bool => $scope->allowsBuilding($building['id'] ?? null))
            ->keyBy('id');

        $unlisted = $this->findBuildings(array_values(array_filter(
            $scope->buildingIds,
            fn (int $buildingId): bool => ! $listedBuildings->has($buildingId),
        )));

        $buildings = collect($scope->buildingIds)
            ->map(fn (int $buildingId): ?array => $listedBuildings->get($buildingId) ?? $unlisted[$buildingId] ?? null)
            ->filter();

        $occupied = $buildings->filter(fn (array $building): bool => filled($building['leaseID'] ?? null))->count();

        return [
            'total' => $buildings->count(),
            'occupied' => $occupied,
            'vacant' => $buildings->count() - $occupied,
        ];
    }

    /**
     * Read one building by id, including vacant ones.
     *
     * @return array<string, mixed>|null
     *
     * @throws PropertywareException
     */
    public function findBuilding(int $buildingId): ?array
    {
        $building = Cache::remember(
            "propertyware:building:{$buildingId}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->get("buildings/{$buildingId}"),
        );

        return isset($building['id']) ? $building : null;
    }

    /**
     * The lease the tenant is on, or null when they have none.
     *
     * Only what a tenant may read about their own lease leaves this method; the
     * owner's entity and the other people on the lease stay behind Lease.
     *
     * @throws PropertywareException
     */
    public function leaseFor(ClientScope $scope): ?Lease
    {
        $leaseId = $scope->leaseIds[0] ?? null;

        return $leaseId === null ? null : $this->findLease($leaseId);
    }

    /**
     * Every lease the tenant is or was on.
     *
     * @return array<int, Lease>
     *
     * @throws PropertywareException
     */
    public function leasesFor(ClientScope $scope): array
    {
        return collect($scope->leaseIds)
            ->map(fn (int $leaseId): ?Lease => $this->findLease($leaseId))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @throws PropertywareException
     */
    private function findLease(int $leaseId): ?Lease
    {
        $lease = Cache::remember(
            "propertyware:lease:{$leaseId}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->get("leases/{$leaseId}"),
        );

        return isset($lease['id']) ? Lease::fromArray($lease) : null;
    }

    /**
     * Read several leases by id, fetching the uncached ones at the same time.
     *
     * The rent and dates are extras on a list: if Propertyware will not answer,
     * the list still shows, only without them.
     *
     * @param  array<int, int>  $leaseIds
     * @return array<int, Lease>
     */
    private function findLeases(array $leaseIds): array
    {
        $leaseIds = array_values(array_unique($leaseIds));

        $cached = collect($leaseIds)
            ->mapWithKeys(fn (int $leaseId): array => [$leaseId => Cache::get("propertyware:lease:{$leaseId}")])
            ->filter(fn (mixed $lease): bool => is_array($lease));

        try {
            $fetched = $this->propertyware->getMany(collect($leaseIds)
                ->reject(fn (int $leaseId): bool => $cached->has($leaseId))
                ->mapWithKeys(fn (int $leaseId): array => [$leaseId => "leases/{$leaseId}"])
                ->all());
        } catch (PropertywareException) {
            $fetched = [];
        }

        foreach ($fetched as $leaseId => $lease) {
            if (isset($lease['id'])) {
                Cache::put("propertyware:lease:{$leaseId}", $lease, $this->cacheSeconds);
            }
        }

        return $cached->union($fetched)
            ->filter(fn (array $lease): bool => isset($lease['id']))
            ->map(fn (array $lease): Lease => Lease::fromArray($lease))
            ->all();
    }

    /**
     * Read several buildings by id, fetching the uncached ones at the same time.
     *
     * @param  array<int, int>  $buildingIds
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function findBuildings(array $buildingIds): array
    {
        $buildingIds = array_values(array_unique($buildingIds));

        $cached = collect($buildingIds)
            ->mapWithKeys(fn (int $buildingId): array => [$buildingId => Cache::get("propertyware:building:{$buildingId}")])
            ->filter(fn (mixed $building): bool => is_array($building));

        $fetched = $this->propertyware->getMany(collect($buildingIds)
            ->reject(fn (int $buildingId): bool => $cached->has($buildingId))
            ->mapWithKeys(fn (int $buildingId): array => [$buildingId => "buildings/{$buildingId}"])
            ->all());

        foreach ($fetched as $buildingId => $building) {
            Cache::put("propertyware:building:{$buildingId}", $building, $this->cacheSeconds);
        }

        return $cached->union($fetched)
            ->filter(fn (array $building): bool => isset($building['id']))
            ->all();
    }

    /**
     * Get the buildings relevant to a scope, by portfolio where possible.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function buildingsFor(ClientScope $scope): array
    {
        if ($scope->role === ClientRole::Owner) {
            return $this->buildingsInPortfolios($scope->portfolioIds);
        }

        return $this->buildings();
    }

    /**
     * Get every unit in the account, cached for a short time.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function units(): array
    {
        return $this->collection('units');
    }

    /**
     * Get the buildings across a set of portfolios.
     *
     * @param  array<int, int>  $portfolioIds
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function buildingsInPortfolios(array $portfolioIds): array
    {
        if ($portfolioIds === []) {
            return [];
        }

        if (count($portfolioIds) > self::BULK_FETCH_THRESHOLD) {
            return collect($this->buildings())
                ->filter(fn (array $building): bool => in_array((int) ($building['portfolioID'] ?? 0), $portfolioIds, strict: true))
                ->values()
                ->all();
        }

        $cached = collect($portfolioIds)
            ->mapWithKeys(fn (int $portfolioId): array => [$portfolioId => Cache::get("propertyware:portfolio:{$portfolioId}:buildings")])
            ->filter(fn (mixed $buildings): bool => is_array($buildings));

        $missing = array_values(array_filter($portfolioIds, fn (int $portfolioId): bool => ! $cached->has($portfolioId)));

        $fetched = $missing === [] ? collect() : collect($this->propertyware->listAllForFilters('buildings', 'portfolioID', $missing))
            ->groupBy(fn (array $building): int => (int) ($building['portfolioID'] ?? 0));

        foreach ($missing as $portfolioId) {
            $buildings = $fetched->get($portfolioId, collect())->values()->all();
            Cache::put("propertyware:portfolio:{$portfolioId}:buildings", $buildings, $this->cacheSeconds);
            $cached->put($portfolioId, $buildings);
        }

        return collect($portfolioIds)
            ->flatMap(fn (int $portfolioId): array => $cached->get($portfolioId, []))
            ->all();
    }

    /**
     * Get every building in the account, cached for a short time.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function buildings(): array
    {
        return $this->collection('buildings');
    }

    /**
     * Read a whole Propertyware collection through the cache.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function collection(string $path): array
    {
        return Cache::remember(
            "propertyware:{$path}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->listAll($path),
        );
    }
}
