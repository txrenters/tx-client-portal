<?php

namespace App\Services\Propertyware;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP client for the Propertyware REST API.
 *
 * Propertyware stays the source of truth. The client reads, and its only
 * writes are files clients upload to their own records. Callers are
 * responsible for scoping requests to the signed-in client's own records.
 */
class PropertywareClient
{
    /**
     * The largest page size Propertyware accepts.
     */
    public const MAX_LIMIT = 500;

    /**
     * How many requests may run against Propertyware at the same time.
     */
    public const CONCURRENCY = 5;

    public function __construct(
        private string $baseUrl,
        private ?string $clientId,
        private ?string $clientSecret,
        private ?string $systemId,
        private int $timeout = 15,
    ) {}

    /**
     * Determine whether API credentials are configured.
     */
    public function isConfigured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret) && filled($this->systemId);
    }

    /**
     * Fetch a single resource.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send($path, $query);
    }

    /**
     * Fetch one page of a collection.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function list(string $path, array $query = [], int $limit = 100, int $offset = 0): array
    {
        $response = $this->send($path, [
            ...$query,
            'limit' => min($limit, self::MAX_LIMIT),
            'offset' => $offset,
        ]);

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Upload a file and return the created record.
     *
     * Propertyware takes the file as the only multipart field; everything else
     * (which record it belongs to, where it is published) goes in the query
     * string. Writes are never retried: a retry after a slow response could
     * store the file twice.
     *
     * @param  array<string, string|int|bool>  $query
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function upload(string $path, string $contents, string $fileName, array $query = []): array
    {
        $query = array_map(fn (string|int|bool $value): string|int => is_bool($value) ? ($value ? 'true' : 'false') : $value, $query);

        return $this->write($path, fn (PendingRequest $request): Response => $request
            ->withQueryParameters($query)
            ->attach('file', $contents, $fileName)
            ->post(ltrim($path, '/')));
    }

    /**
     * Create a record from a JSON body and return it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function create(string $path, array $data): array
    {
        return $this->write($path, fn (PendingRequest $request): Response => $request->post(ltrim($path, '/'), $data));
    }

    /**
     * Update a record and return Propertyware's response.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function update(string $path, array $data): array
    {
        return $this->write($path, fn (PendingRequest $request): Response => $request->put(ltrim($path, '/'), $data));
    }

    /**
     * Fetch a file from Propertyware.
     *
     * Propertyware generates large files on demand, so this allows far longer
     * than an ordinary read before giving up.
     *
     * @throws PropertywareException
     */
    public function download(string $path, int $timeout = 120): Response
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware API credentials are not configured.');
        }

        try {
            return $this->request()->timeout($timeout)->get(ltrim($path, '/'))->throw();
        } catch (ConnectionException $exception) {
            throw new PropertywareException('Could not reach Propertyware.', previous: $exception);
        } catch (RequestException $exception) {
            throw new PropertywareException(
                "Propertyware returned {$exception->response->status()} for [{$path}].",
                previous: $exception,
            );
        }
    }

    /**
     * Fetch every page of a collection, up to a safety cap.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function listAll(string $path, array $query = [], int $maxRecords = 5000): array
    {
        $records = [];

        for ($offset = 0; $offset < $maxRecords; $offset += self::MAX_LIMIT) {
            $page = $this->readCollectionPage($path, $query, self::MAX_LIMIT, $offset);

            $records = [...$records, ...$page];

            if (count($page) < self::MAX_LIMIT) {
                break;
            }
        }

        return $records;
    }

    /**
     * Read independent filtered collections with bounded concurrency.
     *
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function listAllForFilters(string $path, string $filter, array $ids, array $query = []): array
    {
        return $this->listAllForQueries($path, array_map(
            fn (int $id): array => [...$query, $filter => $id],
            array_values(array_unique($ids)),
        ));
    }

    /**
     * Read several filtered views of one collection at the same time.
     *
     * @param  array<int, array<string, mixed>>  $queries
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function listAllForQueries(string $path, array $queries): array
    {
        return $this->listAllForRequests(array_map(fn (array $query): array => [$path, $query], array_values($queries)));
    }

    /**
     * Read every page of several collections at the same time, such as one per building.
     *
     * @param  array<int, string>  $paths
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function listAllForPaths(array $paths, array $query = []): array
    {
        return $this->listAllForRequests(array_map(fn (string $path): array => [$path, $query], array_values(array_unique($paths))));
    }

    /**
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $requests
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function listAllForRequests(array $requests): array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware API credentials are not configured.');
        }

        $responses = Http::pool(function (Pool $pool) use ($requests): array {
            return array_map(fn (int $key) => $this->request($pool->as((string) $key))->get($requests[$key][0], [
                ...$requests[$key][1], 'limit' => self::MAX_LIMIT, 'offset' => 0,
            ]), array_keys($requests));
        }, concurrency: self::CONCURRENCY);

        $records = [];

        foreach ($responses as $key => $response) {
            if (! $response instanceof Response) {
                throw new PropertywareException('Could not reach Propertyware.', previous: $response);
            }

            [$path, $query] = $requests[(int) $key];
            $page = $response->successful()
                ? array_values(array_filter($response->json() ?? [], 'is_array'))
                : $this->readCollectionPage($path, $query, self::MAX_LIMIT, 0);
            $records = [...$records, ...$page];

            for ($offset = self::MAX_LIMIT; count($page) === self::MAX_LIMIT; $offset += self::MAX_LIMIT) {
                $page = $this->readCollectionPage($path, $query, self::MAX_LIMIT, $offset);
                $records = [...$records, ...$page];
            }
        }

        return $records;
    }

    /**
     * Fetch several single resources at the same time.
     *
     * @param  array<int|string, string>  $paths
     * @return array<int|string, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function getMany(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware API credentials are not configured.');
        }

        $responses = Http::pool(function (Pool $pool) use ($paths): array {
            return array_map(
                fn (int|string $key) => $this->request($pool->as((string) $key))->get(ltrim($paths[$key], '/')),
                array_keys($paths),
            );
        }, concurrency: self::CONCURRENCY);

        $records = [];

        foreach ($paths as $key => $path) {
            $response = $responses[(string) $key] ?? null;

            if (! $response instanceof Response) {
                throw new PropertywareException('Could not reach Propertyware.', previous: $response instanceof Throwable ? $response : null);
            }

            if ($response->failed()) {
                Log::error('Propertyware rejected a request.', ['path' => $path, 'status' => $response->status()]);

                throw new PropertywareException("Propertyware returned {$response->status()} for [{$path}].");
            }

            $records[$key] = $response->json() ?? [];
        }

        return $records;
    }

    /**
     * Retry work-order pages in smaller chunks when Propertyware cannot render
     * a large response. A failing single record still fails the complete read.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function readCollectionPage(string $path, array $query, int $limit, int $offset): array
    {
        try {
            return $this->list($path, $query, $limit, $offset);
        } catch (PropertywareException $exception) {
            $previous = $exception->getPrevious();

            if ($path !== 'workorders' || $limit === 1 || ! $previous instanceof RequestException || ! $previous->response->serverError()) {
                throw $exception;
            }

            $chunkSize = max(1, intdiv($limit, 5));
            $records = [];

            for ($position = 0; $position < $limit; $position += $chunkSize) {
                $requested = min($chunkSize, $limit - $position);
                $page = $this->readCollectionPage($path, $query, $requested, $offset + $position);
                $records = [...$records, ...$page];

                if (count($page) < $requested) {
                    break;
                }
            }

            return $records;
        }
    }

    /**
     * Send a GET request and return the decoded body.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PropertywareException
     */
    private function send(string $path, array $query): array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware API credentials are not configured.');
        }

        try {
            $response = $this->request()->get(ltrim($path, '/'), $query)->throw();
        } catch (ConnectionException $exception) {
            Log::error('Propertyware request failed to connect.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new PropertywareException('Could not reach Propertyware.', previous: $exception);
        } catch (RequestException $exception) {
            Log::error('Propertyware rejected a request.', [
                'path' => $path,
                'status' => $exception->response->status(),
            ]);

            throw new PropertywareException(
                "Propertyware returned {$exception->response->status()} for [{$path}].",
                previous: $exception,
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Send a write once, without the retries reads use.
     *
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    private function write(string $path, callable $send): array
    {
        if (! $this->isConfigured()) {
            throw new PropertywareException('Propertyware API credentials are not configured.');
        }

        try {
            $response = $send($this->request()->retry(0)->timeout(60))->throw();
        } catch (ConnectionException $exception) {
            Log::error('Propertyware write failed to connect.', ['path' => $path, 'message' => $exception->getMessage()]);

            throw new PropertywareException('Could not reach Propertyware.', previous: $exception);
        } catch (RequestException $exception) {
            Log::error('Propertyware rejected a write.', [
                'path' => $path,
                'status' => $exception->response->status(),
                'userMessage' => $exception->response->json('userMessage'),
            ]);

            throw new PropertywareException("Propertyware returned {$exception->response->status()} for [{$path}].", previous: $exception);
        }

        return $response->json() ?? [];
    }

    /**
     * Build the authenticated, retrying request.
     */
    private function request(?PendingRequest $request = null): PendingRequest
    {
        return ($request ?? Http::withOptions([]))->baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(5)
            ->retry(2, 200, throw: false)
            ->withHeaders([
                'x-propertyware-client-id' => $this->clientId,
                'x-propertyware-client-secret' => $this->clientSecret,
                'x-propertyware-system-id' => $this->systemId,
            ]);
    }
}
