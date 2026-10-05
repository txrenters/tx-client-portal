<?php

use App\Services\Leases\ExpiringLeases;
use App\Services\Propertyware\PropertywareClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function leaseRecord(array $overrides = []): array
{
    return [
        'id' => 55, 'status' => 'Active', 'active' => true, 'portfolioID' => 2, 'buildingID' => 7, 'unitID' => 70,
        'leaseName' => 'Tenant - Cotenant', 'startDate' => '2025-01-06', 'endDate' => '2027-01-05',
        'contacts' => [
            ['id' => 900, 'role' => 'Primary', 'namedOnLease' => true, 'firstName' => 'Taylor', 'lastName' => 'Tenant'],
            ['id' => 901, 'role' => 'Secondary', 'namedOnLease' => true, 'firstName' => 'Casey', 'lastName' => 'Cotenant'],
            ['id' => 902, 'role' => 'Guarantor', 'namedOnLease' => true, 'firstName' => 'Gina', 'lastName' => 'Guarantor'],
            ['id' => 903, 'role' => 'Tenant', 'namedOnLease' => false, 'firstName' => 'Kid', 'lastName' => 'Tenant'],
        ],
        ...$overrides,
    ];
}

function fakeLeases(array $leases): ExpiringLeases
{
    Http::fake(function (Request $request) use ($leases) {
        return match (true) {
            str_contains($request->url(), '/leases') => Http::response($leases),
            str_contains($request->url(), '/buildings/7') => Http::response(['id' => 7, 'name' => '7734 Park Falls, ', 'address' => ['address' => '7734 Park Falls Dr']]),
            str_contains($request->url(), '/buildings/8') => Http::response(['id' => 8, 'name' => '', 'address' => ['address' => '12 Elm St']]),
            str_contains($request->url(), '/units/70') => Http::response(['id' => 70, 'name' => 'Unit A']),
            default => Http::response([], 404),
        };
    });

    return new ExpiringLeases(new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system'));
}

test('asks Propertyware for leases ending in the window', function () {
    fakeLeases([])->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/leases')
        && $request->data()['endDateStart'] === '2026-10-03'
        && $request->data()['endDateEnd'] === '2027-01-01');
});

test('keeps active leases only, with property labels and the tenants named on them', function () {
    $leases = fakeLeases([
        leaseRecord(),
        leaseRecord(['id' => 56, 'status' => 'Terminated']),
        leaseRecord(['id' => 57, 'status' => 'Active - Notice Given']),
    ])->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    expect($leases)->toHaveCount(1);
    $lease = $leases[0];

    expect($lease->id)->toBe(55)
        ->and($lease->portfolioId)->toBe(2)
        ->and($lease->buildingId)->toBe(7)
        ->and($lease->building)->toBe('7734 Park Falls')
        ->and($lease->unit)->toBe('Unit A')
        ->and($lease->startDate?->toDateString())->toBe('2025-01-06')
        ->and($lease->endDate->toDateString())->toBe('2027-01-05')
        ->and($lease->tenantContactIds)->toBe(['900', '901'])
        ->and($lease->renewalDeadline()->toDateString())->toBe('2026-11-06')
        ->and($lease->daysLeft(CarbonImmutable::parse('2026-10-07')))->toBe(90);
});

test('a home without a unit takes the building name, and a nameless building its address', function () {
    $leases = fakeLeases([leaseRecord(['buildingID' => 8, 'unitID' => null])])
        ->between(CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2027-01-01'));

    expect($leases[0]->building)->toBe('12 Elm St')
        ->and($leases[0]->unit)->toBe('12 Elm St');
});
