<?php

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\Data\PropertyDetails;

function buildingRecord(): array
{
    $field = fn (string $name, mixed $value, string $type = 'TEXT'): array => ['fieldName' => $name, 'value' => $value, 'dataType' => $type, 'definitionID' => 1];

    return [
        'id' => 7,
        'targetRent' => 1850,
        'targetRentUnits' => 'MONTHLY',
        'maintenanceSpendingLimitAmount' => 300,
        'marketing' => ['petsAllowed' => true, 'petDogAllowed' => true, 'petCatAllowed' => false, 'smokingAllowed' => false],
        'amenities' => [['id' => 1, 'name' => 'Pool', 'code' => 'POOL', 'type' => 'BUILDING']],
        'propertyManagerList' => [['id' => 1, 'roleAsString' => 'Manager', 'userID' => 9, 'name' => 'Pat Manager', 'email' => 'pat@texasrenters.com']],
        'customFields' => [
            $field('Lockbox Code', '4321'),
            $field('Alarm System Code', '9999'),
            $field('Gated Community? Gate Code?', '#1234'),
            $field('Garage Remotes_Garage Code', '5555'),
            $field('Mailbox Keys', 'Box 12, key under mat'),
            $field('Make Ready Notes', 'Internal staff notes'),
            $field('Management Plan', 'Full service'),
            $field('HVAC Filter Location Information', 'Hallway ceiling'),
            $field('HVAC Filter Size 1', '20x25x1'),
            $field('HVAC Filter Size 2', '16x20x1'),
            $field('Pet Restrictions', 'Dogs under 40 lbs'),
            $field('Last HVAC Inspection', '2026-05-04', 'DATE'),
            $field('Fireplace', 'true'),
            $field('Paint Color', ''),
        ],
    ];
}

function detailValues(array $details): array
{
    return collect($details['sections'])->flatMap(fn (array $section): array => $section['items'])->pluck('value', 'label')->all();
}

test('never shares entry codes or staff notes with owners or tenants', function (ClientRole $viewer) {
    $encoded = json_encode(PropertyDetails::for(buildingRecord(), $viewer));

    expect($encoded)->not->toContain('4321')
        ->not->toContain('9999')
        ->not->toContain('#1234')
        ->not->toContain('5555')
        ->not->toContain('key under mat')
        ->not->toContain('Internal staff notes');
})->with([ClientRole::Owner, ClientRole::Resident]);

test('shows owners leasing, management and inspection details', function () {
    $details = PropertyDetails::for(buildingRecord(), ClientRole::Owner);

    expect(collect($details['sections'])->pluck('title')->all())->toBe(['Leasing', 'Management', 'Home systems', 'Inspections'])
        ->and(detailValues($details))->toMatchArray([
            'Target rent' => '$1,850 / monthly',
            'Maintenance approval limit' => '$300',
            'Pets allowed' => 'Yes: dogs',
            'Smoking allowed' => 'No',
            'Management plan' => 'Full service',
            'HVAC filter sizes' => '20x25x1, 16x20x1',
            'Last HVAC inspection' => 'May 4, 2026',
            'Fireplace' => 'Yes',
        ]);
});

test('shows tenants their home\'s systems and pet rules but not the owner\'s terms', function () {
    $details = PropertyDetails::for(buildingRecord(), ClientRole::Resident);

    expect(collect($details['sections'])->pluck('title')->all())->toBe(['Home systems', 'Pets'])
        ->and(detailValues($details))->toHaveKeys(['HVAC filter location', 'Pet restrictions'])
        ->and(detailValues($details))->not->toHaveKeys(['Target rent', 'Management plan', 'Maintenance approval limit']);
});

test('lists amenities and the staff managing the property', function () {
    $details = PropertyDetails::for(buildingRecord(), ClientRole::Resident);

    expect($details['amenities'])->toBe(['Pool'])
        ->and($details['managers'])->toBe([['name' => 'Pat Manager', 'email' => 'pat@texasrenters.com']]);
});

test('treats sizes propertyware left at zero as not set', function () {
    $property = Property::fromArray([
        'id' => 7, 'numberOfBedrooms' => 0, 'numberOfBathrooms' => 0, 'totalArea' => '0', 'areaUnits' => 'Sq Ft',
        'address' => ['address' => '12 Elm St'],
    ]);

    expect($property->bedrooms)->toBeNull()
        ->and($property->bathrooms)->toBeNull()
        ->and($property->area)->toBeNull();
});
