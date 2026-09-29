<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Enums\WorkOrderStatus;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\WorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Reads the maintenance requests a client is allowed to see.
 *
 * Propertyware filters work orders by building or portfolio, so each client's
 * requests are fetched directly rather than scanned out of the whole account.
 * Requests staff have not published to the client's portal stay hidden.
 */
class MaintenanceSummary
{
    public function __construct(
        private PropertywareClient $propertyware,
        private PropertywareDirectory $directory,
        private int $cacheSeconds = 300,
    ) {}

    /**
     * Get the client's requests grouped by status, newest first.
     *
     * @return array{status: string, groups: array<string, array<int, array<string, mixed>>>, counts: array<string, int>}
     */
    public function groups(ClientScope $scope, ?int $buildingId = null): array
    {
        try {
            $workOrders = $this->forClient($scope)
                ->when($buildingId !== null, fn (Collection $workOrders): Collection => $workOrders
                    ->filter(fn (WorkOrder $workOrder): bool => $workOrder->buildingId === $buildingId)
                    ->values());
            $addresses = $this->directory->addressesForBuildings(
                $workOrders->pluck('buildingId')->filter()->unique()->values()->all(),
            );
        } catch (PropertywareException) {
            return ['status' => 'error', 'groups' => [], 'counts' => []];
        }

        $groups = collect(WorkOrderStatus::cases())
            ->mapWithKeys(fn (WorkOrderStatus $status): array => [
                $status->value => $workOrders
                    ->filter(fn (WorkOrder $workOrder): bool => $workOrder->status === $status)
                    ->map(fn (WorkOrder $workOrder): array => $workOrder->toArray($addresses[$workOrder->buildingId] ?? null))
                    ->values()
                    ->all(),
            ])
            ->all();

        return [
            'status' => 'ready',
            'groups' => $groups,
            'counts' => collect($groups)->map(fn (array $group): int => count($group))->all(),
        ];
    }

    /**
     * Get one page of the client's requests, newest first, after filtering.
     *
     * Propertyware cannot filter or sort on our behalf, so the full list is
     * read from the cache and only the requested page is sent to the browser.
     *
     * @param  array{status?: ?string, search?: ?string, property?: ?string}  $filters
     * @return array{status: string, counts: array<string, int>, total: int, properties: array<int, string>, workOrders: array<string, mixed>|null}
     */
    public function paginate(ClientScope $scope, array $filters, int $page = 1, int $perPage = 25, string $path = '/'): array
    {
        try {
            $workOrders = $this->forClient($scope);
            $addresses = $this->directory->addressesForBuildings(
                $workOrders->pluck('buildingId')->filter()->unique()->values()->all(),
            );
        } catch (PropertywareException) {
            return ['status' => 'error', 'counts' => [], 'total' => 0, 'properties' => [], 'workOrders' => null];
        }

        $status = WorkOrderStatus::tryFrom((string) ($filters['status'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $property = $filters['property'] ?? null;
        $onlyIds = $filters['ids'] ?? null;

        $matching = $workOrders
            ->filter(fn (WorkOrder $workOrder): bool => $onlyIds === null || in_array($workOrder->id, $onlyIds, true))
            ->filter(fn (WorkOrder $workOrder): bool => $status === null || $workOrder->status === $status)
            ->filter(fn (WorkOrder $workOrder): bool => blank($property) || ($addresses[$workOrder->buildingId] ?? null) === $property)
            ->filter(fn (WorkOrder $workOrder): bool => $search === '' || collect([$workOrder->number, $workOrder->title(), $workOrder->description])
                ->filter()
                ->contains(fn (mixed $value): bool => str_contains(mb_strtolower((string) $value), $search)))
            ->values();

        $paginator = new LengthAwarePaginator(
            $matching->forPage($page, $perPage)
                ->map(fn (WorkOrder $workOrder): array => $workOrder->toArray($addresses[$workOrder->buildingId] ?? null))
                ->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => array_filter([...Arr::except($filters, ['ids']), 'page' => null], filled(...))],
        );

        return [
            'status' => 'ready',
            'counts' => collect(WorkOrderStatus::cases())->mapWithKeys(fn (WorkOrderStatus $case): array => [
                $case->value => $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->status === $case)->count(),
            ])->all(),
            'total' => $workOrders->count(),
            'properties' => collect($addresses)->filter()->unique()->sort()->values()->all(),
            'workOrders' => $paginator->toArray(),
        ];
    }

    /**
     * Get the requests for one of the client's properties, grouped by status.
     *
     * @return array{status: string, groups: array<string, array<int, array<string, mixed>>>, counts: array<string, int>}
     */
    public function groupsForBuilding(ClientScope $scope, int $buildingId): array
    {
        if (! $scope->allowsBuilding($buildingId)) {
            return ['status' => 'ready', 'groups' => [], 'counts' => []];
        }

        return $this->groups($scope, $buildingId);
    }

    /**
     * Get a short summary for a dashboard card, with the six most recently updated requests.
     *
     * @return array{status: string, counts: array<string, int>, recent: array<int, array<string, mixed>>}
     */
    public function summary(ClientScope $scope): array
    {
        try {
            $workOrders = $this->forClient($scope);
            $recent = $workOrders->take(6);
            $addresses = $this->directory->addressesForBuildings(
                $recent->pluck('buildingId')->filter()->unique()->values()->all(),
            );
        } catch (PropertywareException) {
            return ['status' => 'error', 'counts' => [], 'recent' => []];
        }

        return [
            'status' => 'ready',
            'counts' => collect(WorkOrderStatus::cases())->mapWithKeys(fn (WorkOrderStatus $status): array => [
                $status->value => $workOrders->filter(fn (WorkOrder $workOrder): bool => $workOrder->status === $status)->count(),
            ])->all(),
            'recent' => $recent
                ->map(fn (WorkOrder $workOrder): array => $workOrder->toArray($addresses[$workOrder->buildingId] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * Find one request the client is allowed to open.
     *
     * Returns null for anything outside the client's own properties, so a
     * guessed id is indistinguishable from a missing one.
     *
     * @return array<string, mixed>|null
     *
     * @throws PropertywareException
     */
    public function find(ClientScope $scope, string $workOrderId): ?array
    {
        $record = Cache::remember(
            'propertyware:workorder:'.rawurlencode($workOrderId),
            $this->cacheSeconds,
            fn (): array => $this->propertyware->get('workorders/'.rawurlencode($workOrderId)),
        );

        if (! isset($record['id'])) {
            return null;
        }

        $workOrder = WorkOrder::fromArray($record);

        $isVisible = $scope->role === ClientRole::Owner
            ? $workOrder->visibleToOwner
            : $workOrder->visibleToTenant;

        $belongsToClient = $scope->allowsWorkOrder([
            'unitID' => $workOrder->unitId,
            'buildingID' => $workOrder->buildingId,
            'portfolioID' => $workOrder->portfolioId,
        ]);

        if (! $isVisible || ! $belongsToClient) {
            return null;
        }

        return $workOrder->toDetailArray(
            $this->directory->addressesForBuildings(array_filter([$workOrder->buildingId]))[$workOrder->buildingId] ?? null,
            $scope->role,
        );
    }

    /**
     * Create a work order in Propertyware for one of the client's properties.
     *
     * Only the building is required by Propertyware. The request is published
     * back to the client's portal and added to their cached list, so it shows
     * up straight away.
     *
     * @param  array{property_id?: ?int, category: string, description: string, location?: ?string, urgency: string, entry?: ?string, entry_date?: ?string, entry_time?: ?string, access_notes?: ?string}  $request
     * @return array<string, mixed> The created Propertyware record.
     *
     * @throws PropertywareException
     * @throws ValidationException
     */
    public function create(ClientScope $scope, array $request): array
    {
        $isOwner = $scope->role === ClientRole::Owner;
        $buildingId = $this->requestBuilding($scope, $request['property_id'] ?? null);

        // A tenant's own unit in that building, so staff know exactly where to go.
        $unitIds = $isOwner ? [] : array_values(array_keys(array_filter(
            $this->directory->propertyLocations($scope)['units'],
            fn (int $unitBuilding): bool => $unitBuilding === $buildingId,
        )));

        $description = trim($request['description']);

        if (! $isOwner && filled($request['access_notes'] ?? null)) {
            $description .= "\n\n".__('Access notes: :notes', ['notes' => trim($request['access_notes'])]);
        }

        $payload = array_filter([
            'buildingID' => $buildingId,
            'unitIDs' => $unitIds === [] ? null : $unitIds,
            'category' => self::propertywareCategory($request['category']),
            'description' => $description,
            'specificLocation' => filled($request['location'] ?? null) ? trim($request['location']) : null,
            'priority' => $request['urgency'] === 'urgent' ? 'HIGH' : 'MEDIUM',
            'requestedBy' => ctype_digit($scope->contactId) ? (int) $scope->contactId : null,
            'type' => config('maintenance.type'),
            'source' => config('maintenance.source'),
            'searchTag' => config('maintenance.search_tag'),
            'publishToOwnerPortal' => $isOwner,
            'publishToTenantPortal' => ! $isOwner,
            ...$this->entryPermission($isOwner ? [] : $request),
        ], fn (mixed $value): bool => $value !== null);

        $created = $this->propertyware->create('workorders', $payload);

        $this->remember($scope, [
            'lastModifiedDateTime' => now()->toIso8601String(),
            'createdDateTime' => now()->toIso8601String(),
            'status' => 'Open',
            ...$payload,
            ...$created,
        ]);

        return $created;
    }

    /**
     * The categories clients may choose, as they arrive in a request.
     *
     * Propertyware's names can carry stray spaces ("HVAC "), which Laravel trims
     * from every request, so the form offers and validates the trimmed name.
     *
     * @return array<int, string>
     */
    public static function clientCategories(): array
    {
        return array_map(trim(...), config('maintenance.categories'));
    }

    /**
     * Propertyware's exact spelling of a category chosen by a client.
     */
    public static function propertywareCategory(string $category): string
    {
        return collect(config('maintenance.categories'))
            ->first(fn (string $candidate): bool => trim($candidate) === trim($category), $category);
    }

    /**
     * Pick the building a new request is for: an owner chooses one of theirs,
     * a tenant's is their home.
     *
     * @throws ValidationException
     */
    private function requestBuilding(ClientScope $scope, ?int $propertyId): int
    {
        $propertyId ??= count($scope->buildingIds) === 1 ? $scope->buildingIds[0] : null;

        if ($propertyId === null || ! $scope->allowsBuilding($propertyId)) {
            throw ValidationException::withMessages([
                'property_id' => $scope->role === ClientRole::Owner
                    ? __('Choose which of your properties needs work.')
                    : __('We could not find your home to attach this request to.'),
            ]);
        }

        return $propertyId;
    }

    /**
     * Turn the tenant's answer about entry into Propertyware's fields. Without
     * a clear yes, nobody may enter: owners' requests and unanswered ones are "NO".
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function entryPermission(array $request): array
    {
        return match ($request['entry'] ?? null) {
            'anytime' => ['authorizedToEnter' => 'ANYTIME'],
            'scheduled' => [
                'authorizedToEnter' => 'SPECIFIEDTIME',
                'dateToEnter' => $request['entry_date'],
                'hourToEnter' => (int) substr((string) $request['entry_time'], 0, 2),
                // Propertyware only accepts minutes in steps of ten.
                'minuteToEnter' => intdiv((int) substr((string) $request['entry_time'], 3, 2), 10) * 10,
            ],
            default => ['authorizedToEnter' => 'NO'],
        };
    }

    /**
     * Put a newly created work order at the top of the client's cached list,
     * reading the list first if needed, so it shows up (and can take photos)
     * even before Propertyware's own listing includes it.
     *
     * @param  array<string, mixed>  $record
     *
     * @throws PropertywareException
     */
    private function remember(ClientScope $scope, array $record): void
    {
        $key = "propertyware:workorders:{$scope->role->value}:{$scope->contactId}";
        $records = Cache::get($key);
        $records = is_array($records) ? $records : $this->fetch($scope);

        $records = array_values(array_filter(
            $records,
            fn (array $existing): bool => (string) ($existing['id'] ?? '') !== (string) $record['id'],
        ));

        Cache::put($key, [$record, ...$records], $this->cacheSeconds);
    }

    /**
     * Fetch the work orders this client may see.
     *
     * @return Collection<int, WorkOrder>
     *
     * @throws PropertywareException
     */
    public function forClient(ClientScope $scope): Collection
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        $records = Cache::remember(
            "propertyware:workorders:{$scope->role->value}:{$scope->contactId}",
            $this->cacheSeconds,
            fn (): array => $this->fetch($scope),
        );

        return collect($records)
            ->map(fn (array $record): WorkOrder => WorkOrder::fromArray($record))
            ->filter(fn (WorkOrder $workOrder): bool => $scope->role === ClientRole::Owner
                ? $workOrder->visibleToOwner
                : $workOrder->visibleToTenant,
            )
            ->filter(fn (WorkOrder $workOrder): bool => $scope->allowsWorkOrder([
                'unitID' => $workOrder->unitId,
                'buildingID' => $workOrder->buildingId,
                'portfolioID' => $workOrder->portfolioId,
            ]))
            ->unique(fn (WorkOrder $workOrder): string => $workOrder->id)
            ->sortByDesc(fn (WorkOrder $workOrder): int => $workOrder->updatedAt?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * Ask Propertyware for the work orders belonging to the client's properties.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function fetch(ClientScope $scope): array
    {
        $ids = $scope->role === ClientRole::Owner ? $scope->portfolioIds : $scope->buildingIds;

        $key = $scope->role === ClientRole::Owner ? 'portfolioID' : 'buildingID';

        return $this->propertyware->listAllForFilters('workorders', $key, $ids);
    }
}
