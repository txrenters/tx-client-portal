<?php

use App\Models\DemoRecord;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoPdf;
use Carbon\CarbonImmutable;

test('the demo lease always ends inside the 90-day reminder window', function (string $today) {
    $this->travelTo(CarbonImmutable::parse($today, 'America/Chicago'));

    $lease = collect(app(DemoData::class)->leases())->firstWhere('id', DemoData::LEASE);
    $daysLeft = CarbonImmutable::today('America/Chicago')->diffInDays(CarbonImmutable::parse($lease['endDate']), false);

    expect($daysLeft)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(90);
})->with(['2026-10-01', '2026-10-31', '2027-02-28', '2028-02-29', '2026-12-31']);

test('every demo id is far outside Propertyware\'s own range', function () {
    $data = app(DemoData::class);
    $ids = collect([
        ...$data->buildings(), ...$data->leases(), ...$data->workOrders(), ...$data->documents(), ...$data->vendors(),
    ])->pluck('id')->map(fn ($id) => (int) $id);

    expect($ids->min())->toBeGreaterThanOrEqual(900000000);
});

test('the owner has one leased and one vacant home, and the tenant lives in the leased one', function () {
    $data = app(DemoData::class);

    expect(collect($data->contact(DemoData::OWNER_CONTACT)['attachedPortfolios'])->pluck('id')->all())->toBe([DemoData::PORTFOLIO])
        ->and(collect($data->contact(DemoData::TENANT_CONTACT)['attachedLeases'])->pluck('id')->all())->toBe([DemoData::LEASE])
        ->and(collect($data->buildings())->pluck('leaseID', 'id')->all())->toBe([DemoData::HOME_LEASED => DemoData::LEASE, DemoData::HOME_VACANT => null]);
});

test('one work order is waiting for the owner\'s approval', function () {
    $waiting = collect(app(DemoData::class)->workOrders())
        ->filter(fn (array $order) => $order['status'] === 'Open' && $order['approved'] === false);

    expect($waiting->pluck('id')->all())->toBe([(string) DemoData::WORK_ORDER_APPROVAL]);
});

test('the tenant owes this month\'s rent and has paid every earlier month', function () {
    $balance = collect(app(DemoData::class)->leaseAdjustments())->sum('amount');

    expect($balance)->toBe(DemoData::RENT);
});

test('a stored record gets an id in the demo range and is read back', function () {
    $payload = DemoRecord::store('workorder', fn (int $id) => ['id' => (string) $id, 'description' => 'Test']);

    expect((int) $payload['id'])->toBeGreaterThanOrEqual(900500000)
        ->and(DemoRecord::payloads('workorder'))->toBe([$payload]);
});

test('the demo pdf is a pdf', function () {
    expect(DemoPdf::make('Lease Agreement'))->toStartWith('%PDF-1.4')->toContain('Lease Agreement')->toEndWith("%%EOF\n");
});
