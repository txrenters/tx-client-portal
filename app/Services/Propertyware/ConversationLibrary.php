<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Conversation;
use App\Services\Propertyware\Data\WorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the conversations TexasRenters.com keeps with a client in Propertyware.
 *
 * Only the client's own conversation type is read: owners get OWNER threads
 * and residents TENANT threads. Internal MANAGEMENT_TEAM notes are never
 * requested, since they carry call notes about other people.
 */
class ConversationLibrary
{
    public function __construct(
        private PropertywareClient $propertyware,
        private PropertywareDirectory $directory,
        private MaintenanceSummary $maintenance,
        private int $cacheSeconds = 300,
    ) {}

    /**
     * The conversation type the client may read.
     */
    public static function typeFor(ClientRole $role): string
    {
        return $role === ClientRole::Owner ? 'OWNER' : 'TENANT';
    }

    /**
     * Every conversation on the client's contact record and buildings, most recent first.
     *
     * @return Collection<int, Conversation>
     *
     * @throws PropertywareException
     */
    public function forClient(ClientScope $scope): Collection
    {
        if ($scope->isEmpty()) {
            return collect();
        }

        $type = self::typeFor($scope->role);

        $records = Cache::remember(
            "propertyware:conversations:{$scope->role->value}:{$scope->contactId}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->listAllForPaths([
                "contacts/{$scope->contactId}/conversations",
                ...array_map(fn (int $buildingId): string => "buildings/{$buildingId}/conversations", $scope->buildingIds),
            ], ['type' => $type]),
        );

        return collect($records)
            ->map(fn (array $record): Conversation => Conversation::fromArray($record))
            // Checked again here so a wrongly typed record can never reach the client.
            ->filter(fn (Conversation $conversation): bool => $conversation->type === $type)
            ->filter(fn (Conversation $conversation): bool => $conversation->buildingId === null || in_array($conversation->buildingId, $scope->buildingIds, true))
            ->filter(fn (Conversation $conversation): bool => $conversation->comments !== [])
            ->unique(fn (Conversation $conversation): string => $conversation->id)
            ->sortByDesc(fn (Conversation $conversation): int => $conversation->updatedAt()?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * One page of the client's conversations, optionally for one property or their account.
     *
     * @param  array{search?: ?string, property?: int|string|null}  $filters  "account" narrows to conversations on the client's own record.
     * @return array{total: int, properties: array<int, array{id: int, label: string}>, results: array<string, mixed>}
     *
     * @throws PropertywareException
     */
    public function paginate(ClientScope $scope, array $filters, int $page = 1, int $perPage = 15, string $path = '/'): array
    {
        $all = $this->forClient($scope);
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $property = $filters['property'] ?? null;

        $matching = $all
            ->filter(fn (Conversation $conversation): bool => match (true) {
                $property === null => true,
                $property === 'account' => $conversation->buildingId === null,
                default => $conversation->buildingId === (int) $property,
            })
            ->filter(fn (Conversation $conversation): bool => $search === '' || $conversation->matches($search))
            ->values();

        $pageItems = $matching->forPage($page, $perPage)->values();
        $addresses = $this->directory->addressesForBuildings($all->pluck('buildingId')->filter()->unique()->values()->all());

        $paginator = new LengthAwarePaginator(
            $this->present($scope, $pageItems, $addresses),
            $matching->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => array_filter([...Arr::only($filters, ['search', 'property']), 'page' => null], filled(...))],
        );

        return [
            'total' => $all->count(),
            'properties' => collect($addresses)
                ->map(fn (string $label, int $id): array => ['id' => $id, 'label' => $label])
                ->sortBy('label')
                ->values()
                ->all(),
            'results' => $paginator->toArray(),
        ];
    }

    /**
     * The latest conversations about one of the client's buildings.
     *
     * @return array{total: int, conversations: array<int, array<string, mixed>>}
     *
     * @throws PropertywareException
     */
    public function forBuilding(ClientScope $scope, int $buildingId, int $limit = 10): array
    {
        $conversations = $this->buildingConversations($scope, $buildingId);

        return [
            'total' => $conversations->count(),
            'conversations' => $this->present($scope, $conversations->take($limit), $this->directory->addressesForBuildings([$buildingId])),
        ];
    }

    /**
     * Conversations about the client's account rather than any one property.
     *
     * Propertyware keeps these against the contact, so they belong with the
     * client rather than on a property page.
     *
     * @return array{total: int, conversations: array<int, array<string, mixed>>}
     *
     * @throws PropertywareException
     */
    public function forAccount(ClientScope $scope, int $limit = 5): array
    {
        $conversations = $this->forClient($scope)
            ->filter(fn (Conversation $conversation): bool => $conversation->buildingId === null);

        return [
            'total' => $conversations->count(),
            'conversations' => $this->present($scope, $conversations->take($limit), []),
        ];
    }

    /**
     * Conversations on a work order's building that mention its number,
     * since Propertyware keeps no conversations on work orders themselves.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function forWorkOrder(ClientScope $scope, ?int $buildingId, ?string $number): array
    {
        if ($buildingId === null || blank($number)) {
            return [];
        }

        $conversations = $this->buildingConversations($scope, $buildingId)
            ->filter(fn (Conversation $conversation): bool => in_array($number, $conversation->mentionedWorkOrderNumbers(), true))
            ->values();

        return $this->present($scope, $conversations, $this->directory->addressesForBuildings([$buildingId]));
    }

    /**
     * One building's conversations, read on their own so a single page doesn't wait on the whole portfolio.
     *
     * @return Collection<int, Conversation>
     *
     * @throws PropertywareException
     */
    private function buildingConversations(ClientScope $scope, int $buildingId): Collection
    {
        if (! in_array($buildingId, $scope->buildingIds, true)) {
            return collect();
        }

        $type = self::typeFor($scope->role);

        $records = Cache::remember(
            "propertyware:conversations:building:{$buildingId}:{$type}",
            $this->cacheSeconds,
            fn (): array => $this->propertyware->listAll("buildings/{$buildingId}/conversations", ['type' => $type]),
        );

        return collect($records)
            ->map(fn (array $record): Conversation => Conversation::fromArray($record))
            ->filter(fn (Conversation $conversation): bool => $conversation->type === $type && $conversation->buildingId === $buildingId && $conversation->comments !== [])
            ->sortByDesc(fn (Conversation $conversation): int => $conversation->updatedAt()?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @param  array<int, string>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private function present(ClientScope $scope, Collection $conversations, array $addresses): array
    {
        $mentioned = $conversations->flatMap(fn (Conversation $conversation): array => $conversation->mentionedWorkOrderNumbers())->unique();

        // Only link work orders the client can open; the list is already cached for the maintenance pages.
        $workOrders = $mentioned->isEmpty() ? collect() : $this->maintenance->forClient($scope)
            ->filter(fn (WorkOrder $workOrder): bool => $mentioned->contains($workOrder->number))
            ->keyBy('number');

        $staffLogins = $this->staffLogins($conversations);

        return $conversations
            ->map(fn (Conversation $conversation): array => $conversation->toArray(
                $conversation->buildingId === null ? null : ($addresses[$conversation->buildingId] ?? null),
                collect($conversation->mentionedWorkOrderNumbers())
                    ->filter(fn (string $number): bool => $workOrders->has($number))
                    ->map(fn (string $number): array => ['id' => $workOrders[$number]->id, 'number' => $number])
                    ->values()
                    ->all(),
                $staffLogins,
            ))
            ->values()
            ->all();
    }

    /**
     * Logins whose messages are from TexasRenters.com: the configured staff
     * list, plus the managers Propertyware lists on these buildings.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, string>
     */
    private function staffLogins(Collection $conversations): array
    {
        $buildingIds = $conversations->pluck('buildingId')->filter()->unique()->values()->all();

        $managers = collect($buildingIds === [] ? [] : $this->directory->findBuildings($buildingIds))
            ->flatMap(fn (array $building): array => $building['propertyManagerList'] ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn (string $login): string => strtolower($login));

        return $managers->merge(config()->array('services.propertyware.staff_logins', []))->unique()->values()->all();
    }
}
