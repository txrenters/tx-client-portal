<?php

namespace App\Services\Propertyware;

use App\Enums\WorkOrderStatus;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\Data\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds one newest-first history of everything that happened at a property:
 * work orders opened, scheduled, completed or canceled, and files added.
 */
class PropertyTimeline
{
    public function __construct(
        private MaintenanceSummary $maintenance,
        private DocumentLibrary $documents,
    ) {}

    /**
     * Get the property's most recent events.
     *
     * @return array<int, array{type: string, title: string, detail: ?string, occurredAt: string, workOrderId: ?string, documentId: ?string}>
     *
     * @throws PropertywareException
     */
    public function forProperty(ClientScope $scope, int $buildingId, int $limit = 50): array
    {
        $workOrders = $this->maintenance->forClient($scope)->keyBy('id');

        $workOrderEvents = $workOrders
            ->filter(fn (WorkOrder $workOrder): bool => $workOrder->buildingId === $buildingId)
            ->flatMap(fn (WorkOrder $workOrder): array => $this->workOrderEvents($workOrder));

        // A large account's files may still be importing; its history fills in once they arrive.
        $documentEvents = ! $this->documents->isReady($scope) ? collect() : $this->documents
            ->filter($this->documents->forClient($scope), ['property' => $buildingId], $workOrders, $this->documents->propertyLocations($scope))
            ->filter(fn (Document $document): bool => $document->createdAt !== null)
            ->map(fn (Document $document): array => $this->event(
                'document_added',
                __('Document added'),
                $document->fileName,
                $document->createdAt,
                workOrderId: $document->workOrderId(),
                documentId: $document->id,
            ));

        return (new Collection([...$workOrderEvents->all(), ...$documentEvents->all()]))
            ->sortByDesc('occurredAt')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{type: string, title: string, detail: ?string, occurredAt: string, workOrderId: ?string, documentId: ?string}>
     */
    private function workOrderEvents(WorkOrder $workOrder): array
    {
        $label = __('Work order #:number', ['number' => $workOrder->number ?? $workOrder->id]);

        return array_values(array_filter([
            $workOrder->createdAt === null ? null : $this->event('work_order_opened', __(':label opened', ['label' => $label]), $workOrder->title(), $workOrder->createdAt, $workOrder->id),
            $workOrder->scheduledFor === null ? null : $this->event('work_order_scheduled', __(':label scheduled', ['label' => $label]), $workOrder->title(), $workOrder->scheduledFor, $workOrder->id),
            $workOrder->completedAt === null ? null : $this->event('work_order_completed', __(':label completed', ['label' => $label]), $workOrder->title(), $workOrder->completedAt, $workOrder->id),
            $workOrder->status === WorkOrderStatus::Canceled && $workOrder->updatedAt !== null
                ? $this->event('work_order_canceled', __(':label canceled', ['label' => $label]), $workOrder->title(), $workOrder->updatedAt, $workOrder->id)
                : null,
        ]));
    }

    /**
     * @return array{type: string, title: string, detail: ?string, occurredAt: string, workOrderId: ?string, documentId: ?string}
     */
    private function event(string $type, string $title, ?string $detail, Carbon $at, ?string $workOrderId = null, ?string $documentId = null): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'occurredAt' => $at->toIso8601String(),
            'workOrderId' => $workOrderId,
            'documentId' => $documentId,
        ];
    }
}
