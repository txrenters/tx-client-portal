<?php

use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function propertyware(?string $clientId = 'client-id'): PropertywareClient
{
    return new PropertywareClient(
        'https://api.propertyware.test/pw/api/rest/v1',
        $clientId,
        'client-secret',
        'system-id',
    );
}

test('authenticates with the propertyware headers', function () {
    Http::fake(['*' => Http::response([['id' => 1]])]);

    propertyware()->list('buildings');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.propertyware.test/pw/api/rest/v1/buildings?limit=100&offset=0'
        && $request->header('x-propertyware-client-id') === ['client-id']
        && $request->header('x-propertyware-client-secret') === ['client-secret']
        && $request->header('x-propertyware-system-id') === ['system-id'],
    );
});

test('caps the page size at the propertyware maximum', function () {
    Http::fake(['*' => Http::response([])]);

    propertyware()->list('units', limit: 5000, offset: 20);

    Http::assertSent(fn (Request $request) => $request['limit'] === PropertywareClient::MAX_LIMIT
        && $request['offset'] === 20,
    );
});

test('passes filters through to propertyware', function () {
    Http::fake(['*' => Http::response([])]);

    propertyware()->list('workorders', ['unitID' => '4321']);

    Http::assertSent(fn (Request $request) => $request['unitID'] === '4321');
});

test('returns only the records from a collection response', function () {
    Http::fake(['*' => Http::response([['id' => 1], 'unexpected', ['id' => 2]])]);

    expect(propertyware()->list('buildings'))->toBe([['id' => 1], ['id' => 2]]);
});

test('throws when propertyware rejects the request', function () {
    Http::fake(['*' => Http::response(['error' => 'Forbidden'], 403)]);

    expect(fn () => propertyware()->list('leases'))
        ->toThrow(PropertywareException::class, 'Propertyware returned 403 for [leases].');

    try {
        propertyware()->list('leases');
    } catch (PropertywareException $exception) {
        expect($exception)->not->toBeInstanceOf(PropertywareUnavailableException::class);
    }
});

test('throws an unavailable error when propertyware is down or busy', function (int $status) {
    Http::fake(['*' => Http::response(['error' => 'Unavailable'], $status)]);

    expect(fn () => propertyware()->list('leases'))
        ->toThrow(PropertywareUnavailableException::class, "Propertyware returned {$status} for [leases].");
})->with([503, 500, 429]);

test('throws when propertyware cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => propertyware()->get('buildings/1'))
        ->toThrow(PropertywareUnavailableException::class, 'Could not reach Propertyware.');
});

test('throws without sending a request when credentials are missing', function () {
    Http::fake();

    expect(fn () => propertyware(clientId: null)->list('buildings'))
        ->toThrow(PropertywareException::class, 'Propertyware API credentials are not configured.');

    Http::assertNothingSent();
});

test('reports whether credentials are configured', function () {
    expect(propertyware()->isConfigured())->toBeTrue()
        ->and(propertyware(clientId: null)->isConfigured())->toBeFalse();
});

test('reads every page of a collection', function () {
    $firstPage = array_map(fn (int $id): array => ['id' => $id], range(1, PropertywareClient::MAX_LIMIT));

    Http::fakeSequence()
        ->push($firstPage)
        ->push([['id' => 501]]);

    expect(propertyware()->listAll('buildings'))->toHaveCount(PropertywareClient::MAX_LIMIT + 1);

    Http::assertSentCount(2);
});

test('stops paging at the safety cap', function () {
    Http::fake(['*' => Http::response(array_map(fn (int $id): array => ['id' => $id], range(1, PropertywareClient::MAX_LIMIT)))]);

    propertyware()->listAll('buildings', maxRecords: 1000);

    Http::assertSentCount(2);
});

test('retries failing work order pages in smaller chunks without losing records', function () {
    Http::preventStrayRequests();
    $records = array_map(fn (int $id): array => ['id' => $id], range(1, 502));
    Http::fake(['*/workorders*' => function (Request $request) use ($records) {
        if ((int) $request['offset'] === 0 && (int) $request['limit'] > 20) {
            return Http::response(['error' => 'Cannot render page'], 500);
        }

        return Http::response(array_slice($records, (int) $request['offset'], (int) $request['limit']));
    }]);

    expect(propertyware()->listAll('workorders', ['portfolioID' => 123]))->toBe($records);

    Http::assertSent(fn (Request $request) => (int) $request['limit'] === 20 && (int) $request['portfolioID'] === 123);
});

test('does not return partial work orders when a single record still fails', function () {
    Http::preventStrayRequests();
    Http::fake(['*/workorders*' => Http::response(['error' => 'Broken record'], 500)]);

    expect(fn () => propertyware()->listAll('workorders'))->toThrow(PropertywareException::class);

    Http::assertSent(fn (Request $request) => (int) $request['limit'] === 1);
});

test('does not split work order pages when access is denied', function () {
    Http::preventStrayRequests();
    Http::fake(['*/workorders*' => Http::response(['error' => 'Forbidden'], 403)]);

    expect(fn () => propertyware()->listAll('workorders'))->toThrow(PropertywareException::class);

    Http::assertNotSent(fn (Request $request) => (int) $request['limit'] < 500);
});

test('loads filtered collections concurrently and continues pagination', function () {
    Http::preventStrayRequests();
    Http::fake(['*/workorders*' => function (Request $request) {
        if ((int) $request['portfolioID'] === 2) {
            return Http::response([['id' => 900]]);
        }

        return Http::response((int) $request['offset'] === 0
            ? array_map(fn (int $id): array => ['id' => $id], range(1, 500))
            : [['id' => 501]]);
    }]);

    $records = propertyware()->listAllForFilters('workorders', 'portfolioID', [1, 2]);

    expect(collect($records)->pluck('id')->sort()->values()->all())->toBe([...range(1, 501), 900]);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->header('x-propertyware-client-id') === ['client-id']);
});

test('never retries an upload, so a file is not stored twice', function () {
    Http::fake(['*' => Http::sequence()->push(null, 500)->push(['id' => 1])]);
    $client = new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system');

    expect(fn () => $client->upload('docs', 'bytes', 'a.pdf', ['entityType' => 'Lease', 'entityId' => 55]))
        ->toThrow(PropertywareException::class);

    Http::assertSentCount(1);
});
