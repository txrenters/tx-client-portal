<?php

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\ClientScope;

test('a resident only sees work orders for their own unit or building', function (array $workOrder, bool $allowed) {
    $scope = new ClientScope(
        role: ClientRole::Resident,
        contactId: '900',
        leaseIds: [55],
        unitIds: [11],
        buildingIds: [7],
    );

    expect($scope->allowsWorkOrder($workOrder))->toBe($allowed);
})->with([
    'own unit' => [['unitID' => 11, 'buildingID' => 7, 'portfolioID' => 1], true],
    'own building without a unit' => [['unitID' => null, 'buildingID' => 7, 'portfolioID' => 1], true],
    'another resident unit' => [['unitID' => 12, 'buildingID' => 8, 'portfolioID' => 1], false],
    'no location at all' => [['unitID' => null, 'buildingID' => null, 'portfolioID' => 1], false],
]);

test('an owner only sees work orders in their own portfolio', function (array $workOrder, bool $allowed) {
    $scope = new ClientScope(
        role: ClientRole::Owner,
        contactId: '500',
        portfolioIds: [2],
        unitIds: [12],
        buildingIds: [8],
    );

    expect($scope->allowsWorkOrder($workOrder))->toBe($allowed);
})->with([
    'own portfolio' => [['portfolioID' => 2, 'unitID' => 12], true],
    'another portfolio' => [['portfolioID' => 3, 'unitID' => 99], false],
    'a unit they own but another portfolio id' => [['portfolioID' => 3, 'unitID' => 12], false],
    'missing portfolio' => [['portfolioID' => null, 'unitID' => 12], false],
]);

test('an empty scope allows nothing', function () {
    $scope = new ClientScope(role: ClientRole::Resident, contactId: '900');

    expect($scope->isEmpty())->toBeTrue()
        ->and($scope->allowsWorkOrder(['unitID' => 11, 'buildingID' => 7]))->toBeFalse();
});
