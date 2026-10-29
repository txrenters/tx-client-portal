<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Services\Images\UploadedPhotos;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\Data\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Reads the files staff published to a client's portal.
 *
 * Documents are fetched per entity the client already has access to, and files
 * staff have not published to that portal are never returned.
 */
class DocumentLibrary
{
    public function __construct(
        private PropertywareClient $propertyware,
        private MaintenanceSummary $maintenance,
        private PropertywareDirectory $directory,
        private UploadedPhotos $photos,
        private int $cacheSeconds = 300,
    ) {}

    /**
     * How many of the client's newest work orders the upload picker offers.
     */
    public const RECENT_WORK_ORDER_LIMIT = 300;

    /**
     * Get every document the client may see: files on their properties, units,
     * leases, portfolios (owners) and their own contact record.
     *
     * A work order's files (mostly photos) are not documents; they show only
     * on that work order's own page.
     *
     * The list is kept for a day and refreshed in the background once it is
     * older than the cache window, so pages stay fast for large accounts.
     *
     * @return Collection<int, Document>
     *
     * @throws PropertywareException
     */
    public function forClient(ClientScope $scope): Collection
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        $cached = Cache::get($this->cacheKey($scope));

        if (is_array($cached)) {
            if (now()->getTimestamp() - (int) ($cached['fetchedAt'] ?? 0) > $this->cacheSeconds) {
                $this->importInBackground($scope);
            }

            return $this->withoutWorkOrderFiles($this->visible($cached['records'] ?? [], $scope));
        }

        return $this->withoutWorkOrderFiles($this->visible($this->import($scope), $scope));
    }

    /**
     * Drop work order files from a client-wide list. Lists kept from before
     * they left the Documents page still hold some for up to a day.
     *
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, Document>
     */
    private function withoutWorkOrderFiles(Collection $documents): Collection
    {
        return $documents->reject(fn (Document $document): bool => $document->workOrderId() !== null)->values();
    }

    /**
     * Determine whether the client's documents can be shown without waiting on Propertyware.
     */
    public function isReady(ClientScope $scope): bool
    {
        return $scope->isEmpty() || Cache::has($this->cacheKey($scope));
    }

    /**
     * Get one page of documents, or an "importing" status while a large
     * account's documents are still being read from Propertyware.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function pageOrImport(ClientScope $scope, array $filters, int $page = 1, int $perPage = 24, string $path = '/'): array
    {
        if (! $this->isReady($scope)) {
            $this->importInBackground($scope);

            // Tests run the import straight away; a real request comes back for it.
            if (! $this->isReady($scope)) {
                return ['status' => 'importing'];
            }
        }

        return ['status' => 'ready', ...$this->paginate($scope, $filters, $page, $perPage, $path)];
    }

    /**
     * Read the client's documents from Propertyware after the response is sent.
     *
     * Large owners can need hundreds of requests, far longer than a page may
     * take, so pages show an "importing" state and check back instead.
     */
    public function importInBackground(ClientScope $scope): void
    {
        $lock = $this->cacheKey($scope).':importing';

        if (! Cache::add($lock, true, now()->addMinutes(10))) {
            return;
        }

        defer(function () use ($scope, $lock): void {
            set_time_limit(0);

            try {
                $this->import($scope);
            } catch (PropertywareException $exception) {
                Log::warning('Importing client documents from Propertyware failed.', ['message' => $exception->getMessage()]);
            } finally {
                Cache::forget($lock);
            }
        });
    }

    /**
     * Read the client's documents from Propertyware now and remember them.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function import(ClientScope $scope): array
    {
        $records = $this->fetchForEntities([
            'BUILDING' => $scope->buildingIds,
            'UNIT' => $scope->unitIds,
            // Owners hold no lease themselves; the leases on their properties carry their files.
            'LEASE' => $scope->role === ClientRole::Owner
                ? array_keys($this->directory->propertyLocations($scope)['leases'])
                : $scope->leaseIds,
            'PORTFOLIO' => $scope->role === ClientRole::Owner ? $scope->portfolioIds : [],
            'CONTACT' => ctype_digit($scope->contactId) ? [(int) $scope->contactId] : [],
        ]);

        Cache::put($this->cacheKey($scope), ['fetchedAt' => now()->timestamp, 'records' => $records], now()->addDay());

        return $records;
    }

    private function cacheKey(ClientScope $scope): string
    {
        return "propertyware:documents:v2:{$scope->role->value}:{$scope->contactId}";
    }

    /**
     * Get the documents attached to one of the client's work orders.
     *
     * @return Collection<int, Document>
     *
     * @throws PropertywareException
     */
    public function forWorkOrder(ClientScope $scope, string $workOrderId): Collection
    {
        $records = Cache::remember(
            "propertyware:documents:workorder:{$workOrderId}",
            $this->cacheSeconds,
            fn (): array => $this->fetchForEntities(['WORK_ORDER' => [(int) $workOrderId]]),
        );

        return $this->visible($records, $scope);
    }

    /**
     * Find a document attached to one of the client's work orders.
     *
     * @throws PropertywareException
     */
    public function findForWorkOrder(ClientScope $scope, string $workOrderId, string $documentId): ?Document
    {
        return $this->forWorkOrder($scope, $workOrderId)
            ->first(fn (Document $document): bool => $document->id === $documentId);
    }

    /**
     * Find a document the client is allowed to download.
     *
     * @throws PropertywareException
     */
    public function find(ClientScope $scope, string $documentId): ?Document
    {
        return $this->forClient($scope)
            ->first(fn (Document $document): bool => $document->id === $documentId);
    }

    /**
     * Fetch the file itself from Propertyware.
     *
     * Large files are generated on demand and can take a while, so this uses a
     * longer timeout than ordinary reads.
     *
     * @throws PropertywareException
     */
    public function download(Document $document): Response
    {
        return $this->propertyware->download("docs/{$document->id}/download");
    }

    /**
     * Cache a small preview privately; callers must authorize the document first.
     *
     * When this server's image library can't read or write the format (for
     * example GD built without JPEG or WebP), the original image is returned
     * instead so the preview still shows, just as a larger download.
     *
     * @return array{contents: string, mimeType: string}
     */
    public function thumbnail(Document $document): array
    {
        $key = 'propertyware:thumbnail:v1:'.hash('sha256', json_encode([
            config('services.propertyware.base_url'),
            config('services.propertyware.system_id'),
            $document->id,
            $document->fileName,
            $document->createdAt?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        $encoded = Cache::get($key);

        if (is_string($encoded)) {
            return ['contents' => base64_decode($encoded), 'mimeType' => 'image/webp'];
        }

        $original = $this->download($document)->body();

        try {
            $thumbnail = Image::fromBytes($original)
                ->orient()
                ->cover(288, 192)
                ->toWebp()
                ->quality(80)
                ->toBytes();
        } catch (ImageException $exception) {
            Log::warning('Could not make a document thumbnail; sending the original image instead.', [
                'document' => $document->id,
                'message' => $exception->getMessage(),
            ]);

            return ['contents' => $original, 'mimeType' => $document->fileType ?? 'application/octet-stream'];
        }

        Cache::put($key, base64_encode($thumbnail), now()->addDay());

        return ['contents' => $thumbnail, 'mimeType' => 'image/webp'];
    }

    /**
     * Read the documents attached to several entities in one concurrent batch.
     *
     * @param  array<string, array<int, int>>  $entityIdsByType
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function fetchForEntities(array $entityIdsByType): array
    {
        $queries = collect($entityIdsByType)
            ->flatMap(fn (array $entityIds, string $entityType): array => array_map(
                fn (int $entityId): array => ['entityType' => $entityType, 'entityId' => $entityId],
                array_values(array_unique($entityIds)),
            ))
            ->values()
            ->all();

        return $queries === [] ? [] : $this->propertyware->listAllForQueries('docs', $queries);
    }

    /**
     * Upload a client's file to Propertyware and publish it back to their portal.
     *
     * Owners attach files to a property (by default), portfolio, work order or
     * their own record; tenants to their lease (by default), a work order or
     * their own record. Every target must be the client's own.
     *
     * @throws PropertywareException
     * @throws ValidationException
     */
    public function upload(ClientScope $scope, UploadedFile $file, ?string $description = null, ?string $attachTo = null, ?int $targetId = null): Document
    {
        [$entityType, $entityId] = $this->uploadTarget($scope, $attachTo, $targetId);
        $file = $this->photos->optimize($file);
        $fileName = $file->getClientOriginalName();

        $publishing = [
            'publishToOwnerPortal' => $scope->role === ClientRole::Owner,
            'publishToTenantPortal' => $scope->role === ClientRole::Resident,
        ];

        $created = $this->propertyware->upload('docs', (string) $file->get(), $fileName, [
            'entityType' => $entityType,
            'entityId' => $entityId,
            ...$publishing,
        ]);

        // The upload can't carry a description, so it is added afterwards when given.
        if (filled($description)) {
            $this->propertyware->update('docs/'.$created['id'], ['fileName' => $fileName, 'description' => $description]);
        }

        $record = [
            'fileType' => $file->getMimeType(),
            'createdDateTime' => now()->toIso8601String(),
            ...$created,
            'fileName' => $created['fileName'] ?? $fileName,
            'description' => $description,
            ...$publishing,
            // How Propertyware reports the entity type when files are read back.
            'entityType' => match ($entityType) {
                'Owner', 'Tenant' => 'CONTACT',
                default => strtoupper(str_replace(' ', '_', $entityType)),
            },
            'entityId' => $entityId,
        ];

        // Add the file to the kept list rather than forcing a full re-import.
        $cached = Cache::get($this->cacheKey($scope));

        if (is_array($cached)) {
            Cache::put($this->cacheKey($scope), [...$cached, 'records' => [$record, ...($cached['records'] ?? [])]], now()->addDay());
        }

        return Document::fromArray($record);
    }

    /**
     * What a client may attach an upload to, with the Propertyware entity type each uses.
     *
     * @var array<string, string>
     */
    public const ATTACH_TO = [
        'property' => 'Building',
        'portfolio' => 'Portfolio',
        'work_order' => 'Work Order',
        'lease' => 'Lease',
        'account' => 'Owner',
    ];

    /**
     * Work out which Propertyware record a client's upload belongs to, and
     * make sure it is one of the client's own.
     *
     * Owners default to one of their properties and tenants to their lease.
     *
     * @return array{0: string, 1: int}
     *
     * @throws PropertywareException
     * @throws ValidationException
     */
    private function uploadTarget(ClientScope $scope, ?string $attachTo, ?int $targetId): array
    {
        $isOwner = $scope->role === ClientRole::Owner;
        $attachTo ??= $isOwner ? 'property' : 'lease';
        $refuse = fn (string $message): never => throw ValidationException::withMessages(['target_id' => $message]);

        return match (true) {
            $attachTo === 'property' && $isOwner => ['Building', $this->ownTarget(
                $targetId ?? (count($scope->buildingIds) === 1 ? $scope->buildingIds[0] : null),
                $scope->buildingIds,
            ) ?? $refuse(__('Choose which of your properties this file is for.'))],
            $attachTo === 'portfolio' && $isOwner => ['Portfolio', $this->ownTarget(
                $targetId ?? (count($scope->portfolioIds) === 1 ? $scope->portfolioIds[0] : null),
                $scope->portfolioIds,
            ) ?? $refuse(__('Choose which of your portfolios this file is for.'))],
            $attachTo === 'lease' && ! $isOwner => ['Lease', $this->ownTarget(
                $targetId ?? ($scope->leaseIds[0] ?? null),
                $scope->leaseIds,
            ) ?? $refuse(__('We could not find a lease to attach this file to.'))],
            $attachTo === 'work_order' => ['Work Order', $this->ownTarget(
                $targetId,
                $this->maintenance->forClient($scope)->map(fn (WorkOrder $workOrder): int => (int) $workOrder->id)->all(),
            ) ?? $refuse(__('Choose which of your work orders this file is for.'))],
            $attachTo === 'account' && ctype_digit($scope->contactId) => [$isOwner ? 'Owner' : 'Tenant', (int) $scope->contactId],
            default => $refuse(__('This file cannot be attached there.')),
        };
    }

    /**
     * Return the id only when it is one of the client's own.
     *
     * @param  array<int, int>  $allowed
     */
    private function ownTarget(?int $id, array $allowed): ?int
    {
        return $id !== null && in_array($id, $allowed, true) ? $id : null;
    }

    /**
     * Get the choices shown in the upload dialog's "Attach to" pickers.
     *
     * @return array{properties: array<int, array{id: int, label: string}>, portfolios: array<int, array{id: int, label: string}>, workOrders: array<int, array{id: int, label: string}>, leases: array<int, array{id: int, label: string}>}
     *
     * @throws PropertywareException
     */
    public function uploadTargets(ClientScope $scope): array
    {
        $isOwner = $scope->role === ClientRole::Owner;

        return [
            'properties' => $isOwner
                ? collect($this->directory->propertiesFor($scope))
                    ->map(fn (Property $property): array => ['id' => $property->id, 'label' => $property->name ?: $property->address])
                    ->sortBy('label')->values()->all()
                : [],
            'portfolios' => $isOwner
                ? collect($this->directory->portfoliosFor($scope))
                    ->map(fn (array $portfolio): array => ['id' => (int) $portfolio['id'], 'label' => (string) $portfolio['name']])
                    ->sortBy('label')->values()->all()
                : [],
            'workOrders' => $this->maintenance->forClient($scope)
                ->take(self::RECENT_WORK_ORDER_LIMIT)
                ->map(fn (WorkOrder $workOrder): array => [
                    'id' => (int) $workOrder->id,
                    'label' => trim(($workOrder->number ? "#{$workOrder->number} · " : '').$workOrder->title()),
                ])
                ->values()->all(),
            'leases' => $isOwner ? [] : collect($scope->leaseIds)
                ->map(fn (int $id): array => ['id' => $id, 'label' => __('Lease :id', ['id' => $id])])
                ->all(),
        ];
    }

    /**
     * Get one page of the client's documents, newest first, after filtering.
     *
     * The full list stays in the cache; only the requested page leaves the server.
     *
     * @param  array{search?: ?string, category?: ?string, kind?: ?string, type?: ?string, from?: ?string, to?: ?string, property?: int|string|null, work_order?: ?string}  $filters
     * @return array{total: int, categories: array<int, string>, kindCounts: array<string, int>, typeCounts: array<string, int>, properties: array<int, array{id: int, name: string}>, workOrders: array<int, array{id: string, number: ?string, category: ?string, title: string}>, results: array<string, mixed>}
     *
     * @throws PropertywareException
     */
    public function paginate(ClientScope $scope, array $filters, int $page = 1, int $perPage = 24, string $path = '/'): array
    {
        $all = $this->forClient($scope);
        $workOrders = $this->maintenance->forClient($scope)->keyBy('id');
        $locations = $this->directory->propertyLocations($scope);
        $matching = $this->filter($all, $filters, $workOrders, $locations);
        $pageDocuments = $matching->forPage($page, $perPage);

        $propertyIds = $all->map(fn (Document $document): ?int => $this->buildingFor($document, $workOrders, $locations))->filter()->unique()->values();
        $addresses = $this->directory->addressesForBuildings($propertyIds->all());
        $names = collect($this->directory->propertiesFor($scope))
            ->mapWithKeys(fn (Property $property): array => [$property->id => $property->name ?: $property->address])
            ->filter()
            ->all();

        $paginator = new LengthAwarePaginator(
            $pageDocuments
                ->map(fn (Document $document): array => [
                    ...$document->toArray(),
                    'workOrderNumber' => $this->workOrderFor($document, $workOrders)?->number,
                    'propertyName' => $this->propertyLabel($this->buildingFor($document, $workOrders, $locations), $names, $addresses),
                ])
                ->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => array_filter([...$filters, 'page' => null], filled(...))],
        );

        return [
            'total' => $all->count(),
            'categories' => $all->map(fn (Document $document): string => $document->category())->unique()->sort()->values()->all(),
            'kindCounts' => collect(array_keys(Document::KINDS))
                ->mapWithKeys(fn (string $kind): array => [$kind => $all->filter(fn (Document $document): bool => $document->kind() === $kind)->count()])
                ->all(),
            'typeCounts' => collect(['images', 'pdf', 'other'])
                ->mapWithKeys(fn (string $type): array => [$type => $all->filter(fn (Document $document): bool => $document->typeGroup() === $type)->count()])
                ->all(),
            // The filter uses each property's short name (its street address when
            // unnamed, and always for tenants, who never see building names).
            'properties' => $propertyIds
                ->filter(fn (int $id): bool => isset($names[$id]) || isset($addresses[$id]))
                ->map(fn (int $id): array => ['id' => $id, 'name' => $this->propertyLabel($id, $names, $addresses)])
                ->sortBy('name')
                ->values()
                ->all(),
            'workOrders' => $all
                ->map(fn (Document $document): ?WorkOrder => $this->workOrderFor($document, $workOrders))
                ->filter()
                ->unique(fn (WorkOrder $workOrder): string => $workOrder->id)
                ->sortByDesc(fn (WorkOrder $workOrder): int => $workOrder->updatedAt?->getTimestamp() ?? 0)
                ->map(fn (WorkOrder $workOrder): array => ['id' => $workOrder->id, 'number' => $workOrder->number, 'category' => $workOrder->category, 'title' => $workOrder->title()])
                ->values()
                ->all(),
            'results' => $paginator->toArray(),
        ];
    }

    /**
     * Narrow a document list the same way the portal's Documents page does.
     *
     * Dates are whole days in the app's timezone; a document without an upload
     * date is left out once a date filter is set. A property matches its own
     * files and those on its units, its current lease and its work orders.
     *
     * @param  Collection<int, Document>  $documents
     * @param  array{search?: ?string, category?: ?string, kind?: ?string, type?: ?string, from?: ?string, to?: ?string, property?: int|string|null, work_order?: ?string}  $filters
     * @param  Collection<string, WorkOrder>|null  $workOrders  The client's work orders keyed by id, to match their attachments.
     * @param  array{units?: array<int, int>, leases?: array<int, int>}  $locations  Property ids by unit and current lease.
     * @return Collection<int, Document>
     */
    public function filter(Collection $documents, array $filters, ?Collection $workOrders = null, array $locations = []): Collection
    {
        $workOrders ??= collect();
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        // A filter's dates are days in Texas, not UTC days, so a file shared at
        // 7pm on the 18th is not counted as the 19th.
        $zone = config('app.display_timezone');
        $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'], $zone)->startOfDay() : null;
        $to = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to'], $zone)->endOfDay() : null;
        $property = filled($filters['property'] ?? null) ? (int) $filters['property'] : null;
        $workOrder = filled($filters['work_order'] ?? null) ? (string) $filters['work_order'] : null;

        return $documents
            ->filter(fn (Document $document): bool => blank($filters['category'] ?? null) || $document->category() === $filters['category'])
            ->filter(fn (Document $document): bool => blank($filters['kind'] ?? null) || $document->kind() === $filters['kind'])
            ->filter(fn (Document $document): bool => blank($filters['type'] ?? null) || $document->typeGroup() === $filters['type'])
            ->filter(fn (Document $document): bool => $property === null || $this->buildingFor($document, $workOrders, $locations) === $property)
            ->filter(fn (Document $document): bool => $workOrder === null || $document->workOrderId() === $workOrder)
            ->filter(fn (Document $document): bool => ($from === null && $to === null) || ($document->createdAt !== null
                && ($from === null || $document->createdAt >= $from)
                && ($to === null || $document->createdAt <= $to)))
            ->filter(fn (Document $document): bool => $search === '' || collect([$document->fileName, $document->description, $document->category(), $this->workOrderFor($document, $workOrders)?->number])
                ->filter()
                ->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $search)))
            ->values();
    }

    /**
     * Get a property's short name: its Propertyware name, or its address when it
     * has none. Tenants never receive building names, so they get the address.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $addresses
     */
    private function propertyLabel(?int $buildingId, array $names, array $addresses): ?string
    {
        return $buildingId === null ? null : ($names[$buildingId] ?? $addresses[$buildingId] ?? null);
    }

    /**
     * Get the work order a file is attached to, if the client can see it.
     *
     * @param  Collection<string, WorkOrder>  $workOrders
     */
    private function workOrderFor(Document $document, Collection $workOrders): ?WorkOrder
    {
        $workOrderId = $document->workOrderId();

        return $workOrderId === null ? null : $workOrders->get($workOrderId);
    }

    /**
     * Get the property a file belongs to: the building itself, or the building
     * of its unit, current lease or work order.
     *
     * @param  Collection<string, WorkOrder>  $workOrders
     * @param  array{units?: array<int, int>, leases?: array<int, int>}  $locations
     */
    private function buildingFor(Document $document, Collection $workOrders, array $locations = []): ?int
    {
        return match ($document->entityType) {
            'BUILDING' => $document->entityId,
            'UNIT' => ($locations['units'] ?? [])[$document->entityId ?? 0] ?? null,
            'LEASE' => ($locations['leases'] ?? [])[$document->entityId ?? 0] ?? null,
            default => $this->workOrderFor($document, $workOrders)?->buildingId,
        };
    }

    /**
     * Map the client's units and current leases to their property.
     *
     * @return array{units: array<int, int>, leases: array<int, int>}
     *
     * @throws PropertywareException
     */
    public function propertyLocations(ClientScope $scope): array
    {
        return $this->directory->propertyLocations($scope);
    }

    /**
     * Keep only the files published to this client's portal.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return Collection<int, Document>
     */
    private function visible(array $records, ClientScope $scope): Collection
    {
        return collect($records)
            ->map(fn (array $record): Document => Document::fromArray($record))
            ->filter(fn (Document $document): bool => $document->isVisibleTo($scope->role))
            ->unique(fn (Document $document): string => $document->id)
            ->sortByDesc(fn (Document $document): int => $document->createdAt?->getTimestamp() ?? 0)
            ->values();
    }
}
