<?php

use App\Services\Listings\ListingPhotos;
use App\Services\Propertyware\Data\Property;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tx_cma.url' => 'https://cma.test', 'services.tx_cma.token' => 'cma-token']);
});

function elmStreet(): Property
{
    return Property::fromArray(['id' => 7, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX', 'postalCode' => '78701']]);
}

test('returns our listing\'s photos in display order for an exact address match', function () {
    Http::fake(['cma.test/api/v1/listings/property*' => Http::response([
        'match_type' => 'exact',
        'listing' => ['photos' => [
            ['url' => 'https://photos.test/2.jpg', 'order' => 2, 'description' => 'Kitchen'],
            ['url' => 'https://photos.test/1.jpg', 'order' => 1, 'description' => 'Front'],
        ]],
    ])]);

    expect(app(ListingPhotos::class)->for(elmStreet()))->toBe([
        ['url' => 'https://photos.test/1.jpg', 'description' => 'Front'],
        ['url' => 'https://photos.test/2.jpg', 'description' => 'Kitchen'],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cma-token')
        && $request['address'] === '12 Elm St Austin, TX 78701');
});

test('shows no photos unless the address matched exactly', function (int $status, string $matchType) {
    Http::fake(['cma.test/*' => Http::response([
        'match_type' => $matchType,
        'listing' => ['photos' => [['url' => 'https://photos.test/unit.jpg', 'order' => 1]]],
    ], $status)]);

    expect(app(ListingPhotos::class)->for(elmStreet()))->toBe([]);
})->with([
    'another unit in the building' => [200, 'same_building'],
    'no listing of ours' => [404, 'no_match'],
]);

test('asks tx-cma once and reuses the photos', function () {
    Http::fake(['cma.test/*' => Http::response(['match_type' => 'exact', 'listing' => ['photos' => []]])]);

    app(ListingPhotos::class)->for(elmStreet());
    app(ListingPhotos::class)->for(elmStreet());

    Http::assertSentCount(1);
});

test('does not call tx-cma until it is configured', function () {
    Http::fake();
    config(['services.tx_cma.url' => null]);

    expect(app(ListingPhotos::class)->for(elmStreet()))->toBe([]);
    Http::assertNothingSent();
});

test('tries again next time when tx-cma could not answer', function () {
    Http::fake(['cma.test/*' => Http::sequence()
        ->push(['message' => 'Server error'], 500)
        ->push(['match_type' => 'exact', 'listing' => ['photos' => [['url' => 'https://photos.test/1.jpg', 'order' => 1]]]])]);

    expect(app(ListingPhotos::class)->for(elmStreet()))->toBe([])
        ->and(app(ListingPhotos::class)->for(elmStreet()))->toHaveCount(1);
});
