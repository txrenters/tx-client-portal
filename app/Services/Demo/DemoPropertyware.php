<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Propertyware's REST API for the demo logins: the made-up records merged with
 * what the reviewer created, filtered the way Propertyware filters them.
 *
 * A path it doesn't know answers 404, which the client turns into a
 * PropertywareException, so a screen that needs something new fails its test
 * instead of reaching anywhere real.
 */
final class DemoPropertyware
{
    public function __construct(private DemoData $data) {}

    public function respond(Request $request): PromiseInterface
    {
        $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ($request->method()) {
            'GET' => $this->read($path, $query),
            'POST' => match ($path) {
                'workorders' => Http::response($this->createWorkOrder($request->data()), 201),
                'docs' => Http::response($this->uploadDocument($query, $request->data()), 201),
                default => $this->missing(),
            },
            'PUT' => preg_match('#^docs/(\d+)$#', $path, $match)
                ? Http::response($this->updateDocument((int) $match[1], $request->data()))
                : $this->missing(),
            default => $this->missing(),
        };
    }

    /**
     * @param  array<int|string, mixed>  $query
     */
    private function read(string $path, array $query): PromiseInterface
    {
        if (preg_match('#^docs/(\d+)/download$#', $path, $match)) {
            return $this->download((int) $match[1]);
        }

        $records = $this->find($path, $query);

        if ($records === null) {
            return $this->missing();
        }

        if (array_is_list($records) && isset($query['limit'])) {
            $records = array_slice($records, (int) ($query['offset'] ?? 0), (int) $query['limit']);
        }

        return Http::response($records);
    }

    /**
     * @param  array<int|string, mixed>  $query
     * @return array<mixed>|null
     */
    private function find(string $path, array $query): ?array
    {
        $data = $this->data;
        $segments = explode('/', $path);
        $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;

        return match (true) {
            $path === 'contacts' => [],
            $segments[0] === 'contacts' && count($segments) === 2 => $data->contact((int) $id),
            in_array($segments[0], ['contacts', 'buildings'], true) && ($segments[2] ?? null) === 'conversations' => $this->conversations($segments[0], (int) $id, $query['type'] ?? null),
            $path === 'buildings' => $this->where($data->buildings(), 'portfolioID', $query['portfolioID'] ?? null),
            $segments[0] === 'buildings' && count($segments) === 2 => $this->byId($data->buildings(), $id),
            $path === 'units' => [],
            $path === 'leases/adjustments' => $this->where($data->leaseAdjustments(), 'leaseID', $query['leaseID'] ?? null),
            $path === 'leases' => $this->leases($query),
            $segments[0] === 'leases' && count($segments) === 2 => $this->byId($data->leases(), $id),
            $segments[0] === 'portfolios' && count($segments) === 2 => $data->portfolio((int) $id),
            $path === 'portfolios' => [$data->portfolio(DemoData::PORTFOLIO)],
            $path === 'accounting/glaccounts' => $data->glAccounts(),
            $path === 'accounting/generalledger' => $this->generalLedger($query),
            $path === 'bills' => $this->bills($query),
            $path === 'vendors' => $data->vendors(),
            $segments[0] === 'vendors' && count($segments) === 2 => $this->byId($data->vendors(), $id),
            $path === 'workorders' => $this->where($this->where($this->workOrders(), 'portfolioID', $query['portfolioID'] ?? null), 'buildingID', $query['buildingID'] ?? null),
            $segments[0] === 'workorders' && count($segments) === 2 => $this->byId($this->workOrders(), $id),
            $path === 'docs' => $this->where($this->where($this->documents(), 'entityType', $query['entityType'] ?? null), 'entityId', $query['entityId'] ?? null),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workOrders(): array
    {
        return [...$this->data->workOrders(), ...DemoRecord::payloads('workorder')];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(): array
    {
        $edits = collect(DemoRecord::payloads('document_edit'))->keyBy('id');

        return array_map(
            fn (array $document): array => [...$document, ...($edits[$document['id']] ?? [])],
            [...$this->data->documents(), ...DemoRecord::payloads('document')],
        );
    }

    /**
     * @param  array<int|string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function leases(array $query): array
    {
        return array_values(array_filter(
            $this->where($this->data->leases(), 'buildingID', $query['buildingID'] ?? null),
            fn (array $lease): bool => (! isset($query['endDateStart']) || $lease['endDate'] >= $query['endDateStart'])
                && (! isset($query['endDateEnd']) || $lease['endDate'] <= $query['endDateEnd']),
        ));
    }

    /**
     * @param  array<int|string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function generalLedger(array $query): array
    {
        if (isset($query['portfolioID']) && (int) $query['portfolioID'] !== DemoData::PORTFOLIO) {
            return [];
        }

        return array_values(array_filter(
            $this->data->generalLedger(),
            fn (array $transaction): bool => (! isset($query['postDateStart']) || $transaction['postDate'] >= $query['postDateStart'])
                && (! isset($query['postDateEnd']) || $transaction['postDate'] <= $query['postDateEnd']),
        ));
    }

    /**
     * @param  array<int|string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function bills(array $query): array
    {
        return array_values(array_filter(
            $this->data->bills(),
            fn (array $bill): bool => ! isset($query['portfolioID'])
                || in_array((int) $query['portfolioID'], array_map(fn (array $split): int => (int) $split['portfolioID'], $bill['billSplits']), true),
        ));
    }

    /**
     * Propertyware returns a contact's or building's conversations of the asked type, plus the management team's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversations(string $entity, int $id, ?string $type): array
    {
        $entityType = $entity === 'contacts' ? 'Contact' : 'Building';

        return array_values(array_filter(
            $this->data->conversations(),
            fn (array $conversation): bool => $conversation['entityType'] === $entityType && $conversation['entityID'] === $id
                && ($type === null || $conversation['type'] === $type || $conversation['type'] === 'MANAGEMENT_TEAM'),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createWorkOrder(array $payload): array
    {
        return DemoRecord::store('workorder', fn (int $id): array => [
            'status' => 'Open', 'approved' => true, 'portfolioID' => DemoData::PORTFOLIO, 'assignedVendors' => [],
            ...$payload,
            'id' => (string) $id,
            'number' => $id - DemoRecord::FIRST_ID + 9500,
            'createdDateTime' => now()->toIso8601ZuluString(),
            'lastModifiedDateTime' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Propertyware takes the file as the only multipart field and everything else in the query string.
     *
     * @param  array<int|string, mixed>  $query
     * @param  array<int, array{name: string, contents: mixed, filename?: string}>  $parts
     * @return array<string, mixed>
     */
    private function uploadDocument(array $query, array $parts): array
    {
        $file = collect($parts)->firstWhere('name', 'file');
        $fileName = (string) ($file['filename'] ?? 'Document');

        return DemoRecord::store('document', function (int $id) use ($query, $file, $fileName): array {
            Storage::disk('local')->put("demo/{$id}", (string) $file['contents']);

            return [
                'id' => $id,
                'fileName' => $fileName,
                'fileType' => str_ends_with(strtolower($fileName), '.pdf') ? 'application/pdf' : 'application/octet-stream',
                'description' => null,
                'entityType' => (string) $query['entityType'],
                'entityId' => (int) $query['entityId'],
                'publishToOwnerPortal' => ($query['publishToOwnerPortal'] ?? 'false') === 'true',
                'publishToTenantPortal' => ($query['publishToTenantPortal'] ?? 'false') === 'true',
                'createdDateTime' => now()->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function updateDocument(int $id, array $changes): array
    {
        DemoRecord::store('document_edit', fn (): array => ['id' => $id, ...array_intersect_key($changes, array_flip(['fileName', 'description']))]);

        return $this->byId($this->documents(), $id) ?? [];
    }

    private function download(int $id): PromiseInterface
    {
        $document = $this->byId($this->documents(), $id);

        if ($document === null) {
            return $this->missing();
        }

        $stored = Storage::disk('local')->get("demo/{$id}");

        return Http::response(
            $stored ?? DemoPdf::make(pathinfo($document['fileName'], PATHINFO_FILENAME)),
            200,
            ['Content-Type' => $document['fileType']],
        );
    }

    /**
     * Keep the rows whose field equals the value, comparing as Propertyware's string query does.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function where(array $rows, string $field, mixed $value): array
    {
        if ($value === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => (string) ($row[$field] ?? '') === (string) $value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function byId(array $rows, ?int $id): ?array
    {
        return collect($rows)->first(fn (array $row): bool => (int) $row['id'] === $id);
    }

    private function missing(): PromiseInterface
    {
        return Http::response(['userMessage' => 'Not available in the demo.'], 404);
    }
}
