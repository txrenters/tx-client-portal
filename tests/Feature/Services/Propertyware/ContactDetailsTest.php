<?php

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\ContactDetails;

function ownerContactRecord(): array
{
    return [
        'id' => 500,
        'firstName' => 'Olivia',
        'lastName' => 'Owner',
        'company' => '',
        'email' => 'olivia@example.com',
        'mobilePhone' => '(713) 555-0100',
        'workPhone' => '',
        'address' => ['address' => '1 Main St', 'addressCont' => 'Suite 4', 'city' => 'Houston', 'stateRegion' => 'TX', 'postalCode' => '77002', 'country' => 'US'],
        'nameOnCheck' => 'Olivia Owner LLC',
        'drawPaymentMethod' => 'DIRECT_DEPOSIT',
        'ownerEmailStatement' => true,
        'ownerPrintStatement' => false,
        'ownerPublishStatements' => true,
        'ownerEligible1099' => true,
    ];
}

function detailMap(array $sections): array
{
    return collect($sections)->mapWithKeys(fn (array $section): array => [
        $section['title'] => collect($section['items'])->pluck('value', 'label')->all(),
    ])->all();
}

test('shows an owner their contact, mailing and statement details, leaving out blanks', function () {
    expect(detailMap(ContactDetails::for(ownerContactRecord(), ClientRole::Owner)))->toBe([
        'Contact' => ['Name' => 'Olivia Owner', 'Email' => 'olivia@example.com', 'Mobile phone' => '(713) 555-0100'],
        'Mailing address' => ['Address' => '1 Main St, Suite 4, Houston, TX 77002'],
        'Statements & payments' => [
            'Name on checks' => 'Olivia Owner LLC',
            'Payment method' => 'Direct Deposit',
            'Statements' => 'Email, Owner portal',
            'Receives a 1099' => 'Yes',
        ],
    ]);
});

test('leaves owner payment details out for tenants', function () {
    expect(array_keys(detailMap(ContactDetails::for(ownerContactRecord(), ClientRole::Resident))))->toBe(['Contact', 'Mailing address']);
});
