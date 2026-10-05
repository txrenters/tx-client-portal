<?php

// tests/Feature/Leases/LeaseExpiryRecipientsTest.php

use App\Models\ClientAccount;
use App\Services\Leases\ExpiringLease;
use App\Services\Leases\LeaseExpiryRecipients;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function expiringLease(array $tenantContactIds = ['900', '901'], int $portfolioId = 2): ExpiringLease
{
    return new ExpiringLease(55, $portfolioId, 7, '7734 Park Falls', '7734 Park Falls', CarbonImmutable::parse('2025-01-06'), CarbonImmutable::parse('2027-01-05'), $tenantContactIds);
}

function recipientsWithOwners(array $contacts): LeaseExpiryRecipients
{
    Http::fake(function (Request $request) use ($contacts) {
        preg_match('#/contacts/(\d+)#', $request->url(), $match);
        $contact = $contacts[$match[1] ?? ''] ?? null;

        return $contact === 'error' ? Http::response([], 500) : Http::response($contact ?? [], $contact === null ? 404 : 200);
    });

    return new LeaseExpiryRecipients(new PropertywareDirectory(
        new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system'),
        cacheSeconds: 0,
    ));
}

function ownerContact(string $id, array $portfolioIds): array
{
    return ['id' => $id, 'type' => 'OWNER', 'firstName' => 'O', 'lastName' => $id, 'attachedLeases' => [],
        'attachedPortfolios' => array_map(fn (int $p): array => ['id' => $p, 'name' => "P{$p}"], $portfolioIds)];
}

test('every owner of the lease portfolio is told, and nobody else', function () {
    $first = ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    $second = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);
    ClientAccount::factory()->owner()->create(['propertyware_id' => '502']);

    $recipients = recipientsWithOwners([
        '500' => ownerContact('500', [2]),
        '501' => ownerContact('501', [2, 3]),
        '502' => ownerContact('502', [9]),
    ]);

    expect($recipients->owners(expiringLease())->pluck('id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('an owner contact Propertyware has no record of is skipped while the others still resolve', function () {
    ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    $readable = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);

    $recipients = recipientsWithOwners(['501' => ownerContact('501', [2])]);

    expect($recipients->owners(expiringLease())->pluck('id')->all())->toBe([$readable->id]);
});

test('any other failure reading an owner contact is thrown so nothing is half-sent', function () {
    ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);

    $recipients = recipientsWithOwners(['500' => 'error', '501' => ownerContact('501', [2])]);

    $recipients->owners(expiringLease());
})->throws(PropertywareException::class);

test('tenants named on the lease with a portal login are told; others are not', function () {
    $primary = ClientAccount::factory()->resident()->create(['propertyware_id' => '900']);
    $secondary = ClientAccount::factory()->resident()->create(['propertyware_id' => '901']);
    ClientAccount::factory()->resident()->create(['propertyware_id' => '902']); // guarantor, filtered in Task 2
    ClientAccount::factory()->owner()->create(['propertyware_id' => '900']); // same id, wrong role

    $recipients = recipientsWithOwners([]);

    expect($recipients->tenants(expiringLease(['900', '901']))->pluck('id')->sort()->values()->all())
        ->toBe([$primary->id, $secondary->id]);
});

test('an owner whose login is suspended is not told', function () {
    $active = ClientAccount::factory()->owner()->create(['propertyware_id' => '500']);
    $suspended = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);
    $suspended->user->forceFill(['suspended_at' => now()])->save();

    $recipients = recipientsWithOwners(['500' => ownerContact('500', [2]), '501' => ownerContact('501', [2])]);

    expect($recipients->owners(expiringLease())->pluck('id')->all())->toBe([$active->id]);
});

test('a tenant whose login is suspended is not told', function () {
    $active = ClientAccount::factory()->resident()->create(['propertyware_id' => '900']);
    $suspended = ClientAccount::factory()->resident()->create(['propertyware_id' => '901']);
    $suspended->user->forceFill(['suspended_at' => now()])->save();

    expect(recipientsWithOwners([])->tenants(expiringLease(['900', '901']))->pluck('id')->all())->toBe([$active->id]);
});
