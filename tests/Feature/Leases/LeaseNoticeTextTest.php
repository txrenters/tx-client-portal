<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Leases\ExpiringLease;
use App\Services\Leases\LeaseNoticeText;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->lease = new ExpiringLease(55, 2, 7, '7734 Park Falls', '7734 Park Falls', CarbonImmutable::parse('2024-12-27'), CarbonImmutable::parse('2026-12-30'), ['900', '901']);
    $this->today = CarbonImmutable::parse('2026-10-01');
});

test('the owner is told about the property and the dates, nothing about who lives there', function () {
    $owner = ClientAccount::factory()->owner()->for(User::factory()->create(['name' => 'Olivia Owner']))->create();

    $notice = LeaseNoticeText::for($this->lease, $owner, $this->today);

    expect($notice)->toMatchArray([
        'type' => 'lease_expiring',
        'role' => 'owner',
        'account_id' => $owner->id,
        'lease_id' => 55,
        'building_id' => 7,
        'end_date' => '2026-12-30',
        'renewal_deadline' => '2026-10-31',
        'days_left' => 90,
        'title' => 'Lease ending at 7734 Park Falls',
        'body' => "The lease ends on Dec 30, 2026, 90 days from now. It's time to decide whether to renew it or list the property on the market.",
        'url' => route('owner.properties.show', 7),
    ]);

    expect($notice['message'])->toContain('Lease Expiration Date: 12/30/2026')
        ->and($notice['message'])->toContain('Building Name: 7734 Park Falls')
        ->and(json_encode($notice))->not->toContain('Olivia')
        ->and($notice)->not->toHaveKey('greeting_name');
});

test('the tenant gets the office letter, addressed to them', function () {
    $tenant = ClientAccount::factory()->resident()->for(User::factory()->create(['name' => 'Grayling Vital']))->create();

    $notice = LeaseNoticeText::for($this->lease, $tenant, $this->today);

    expect($notice['role'])->toBe('resident')
        ->and($notice['greeting_name'])->toBe('Grayling Vital')
        ->and($notice['title'])->toBe('Your lease ends Dec 30, 2026')
        ->and($notice['body'])->toBe('To renew, your documents must be submitted and approved by Oct 31, 2026. After that, the home goes back on the market.')
        ->and($notice['url'])->toBe(route('resident.lease'))
        ->and($notice['message'])->toBe(<<<'TEXT'
            Dear Grayling Vital,

            This notification is to inform you that your lease will be expiring soon. The details of your current lease are shown below:

            Building Name: 7734 Park Falls
            Unit Name: 7734 Park Falls
            Lease Start Date: 12/27/2024
            Lease Expiration Date: 12/30/2026

            Per your lease agreement, your home will be placed back on the market 60 days before the lease ends. If you've been offered a renewal or extension, all required documents must be submitted and approved at least 60 days prior to your lease end date.

            Best regards,
            The TexasRenters.com Property Management Team
            TEXT);
});

test('a lease with no start date leaves that line out', function () {
    $lease = new ExpiringLease(55, 2, 7, 'A', 'A', null, CarbonImmutable::parse('2026-12-30'), []);
    $tenant = ClientAccount::factory()->resident()->create();

    expect(LeaseNoticeText::for($lease, $tenant, $this->today)['message'])->not->toContain('Lease Start Date');
});
