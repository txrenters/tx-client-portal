<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\RentDueNotification;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Lease;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.propertyware.portal_urls.resident' => 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/tenant.action']);
    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->user)->create();
});

function tenantLease(array $overrides = []): void
{
    $directory = test()->mock(PropertywareDirectory::class);
    $directory->shouldReceive('scopeFor')->andReturn(new ClientScope(ClientRole::Resident, '500', leaseIds: [55]));
    $directory->shouldReceive('leaseFor')->andReturn(Lease::fromArray([
        'id' => 55, 'leaseName' => 'Elm St', 'status' => 'Active', 'active' => true, 'baseRent' => 1850, 'leaseBalance' => 1850,
        'lateFeeRule' => '7.00% of rent charges after due day 3, then 1.00% of rent balance per day, 12.0% of Rent Charges Max',
        ...$overrides,
    ]));
}

test('reminds a tenant who still owes rent before late fees start, once a month', function () {
    Notification::fake();
    tenantLease();
    $this->travelTo('2026-11-01 09:00');
    $this->artisan('rent:remind')->assertSuccessful();
    $this->travelTo('2026-11-02 09:00');
    $this->artisan('rent:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->user, RentDueNotification::class, 1);
    Notification::assertSentTo($this->user, fn (RentDueNotification $notification): bool => $notification->notice['body'] === 'You owe $1,850.00. Pay by Nov 3 to avoid a late fee.'
        && $notification->notice['url'] === route('resident.lease')
        && $notification->notice['pay_url'] === 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/tenant.action');
});

test('does not remind a tenant who has paid', function () {
    Notification::fake();
    tenantLease(['leaseBalance' => 0]);

    $this->travelTo('2026-11-01 09:00');
    $this->artisan('rent:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

test('does not remind once late fees have started', function () {
    Notification::fake();
    tenantLease();

    $this->travelTo('2026-11-04 09:00');
    $this->artisan('rent:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

test('uses the grace day from the lease late fee rule', function () {
    Notification::fake();
    tenantLease(['lateFeeRule' => '$75.00 initial charge after due day 5, then $10.00 per day']);

    $this->travelTo('2026-11-05 09:00');
    $this->artisan('rent:remind')->assertSuccessful();

    Notification::assertSentTo($this->user, fn (RentDueNotification $notification): bool => $notification->notice['pay_by'] === '2026-11-05');
});

test('is listed in the notifications and pushed with the tenant portal unless rent reminders are off', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]])]);
    PushToken::factory()->for($this->user)->create();
    $quiet = User::factory()->create(['notification_preferences' => ['rent_reminders_push' => false]]);
    PushToken::factory()->for($quiet)->create();
    $lease = Lease::fromArray(['id' => 55, 'status' => 'Active', 'active' => true, 'leaseBalance' => 1850]);

    $this->user->notify(new RentDueNotification($this->account, $lease, now()->setDay(3)));
    $quiet->notify(new RentDueNotification(ClientAccount::factory()->resident()->for($quiet)->create(), $lease, now()->setDay(3)));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->data()[0]['data']['type'] === 'rent_due'
        && $request->data()[0]['data']['payUrl'] === 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/tenant.action');

    $this->actingAs($this->user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notices.0.type', 'rent_due')
            ->where('notices.0.payUrl', 'https://app.propertyware.com/pw/portals/houstonpropertymanagement/tenant.action'));
});
