<?php

// tests/Feature/Console/Commands/NotifyExpiringLeasesTest.php

use App\Models\ClientAccount;
use App\Models\LeaseExpiryNotice;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use App\Services\Leases\LeaseExpiryRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-03 09:00:00');
    config(['services.propertyware.discover_accounts' => false]);
    $this->leases = [];
    $this->buildingsFail = false;
    $this->ownerContactFailures = 0;
    $this->tenantContact = ['id' => '900', 'type' => 'TENANT'];

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/contacts/500') && $this->ownerContactFailures > 0) {
            $this->ownerContactFailures--;

            return Http::response([], 500);
        }

        return match (true) {
            str_contains($url, '/leases') => Http::response($this->leases),
            str_contains($url, '/buildings/') => $this->buildingsFail ? Http::response([], 500) : Http::response(['id' => 7, 'name' => '7734 Park Falls']),
            str_contains($url, '/units/') => Http::response([]),
            str_contains($url, '/contacts/500') => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'P']]]),
            str_contains($url, '/contacts/900') => Http::response($this->tenantContact),
            default => Http::response([], 404),
        };
    });

    $this->owner = ClientAccount::factory()->owner()->for(User::factory()->create())->create(['propertyware_id' => '500']);
    $this->tenant = ClientAccount::factory()->resident()->for(User::factory()->create())->create(['propertyware_id' => '900']);
});

function activeLease(int $id, string $endDate, array $overrides = []): array
{
    return ['id' => $id, 'status' => 'Active', 'portfolioID' => 2, 'buildingID' => 7, 'unitID' => null, 'endDate' => $endDate, 'startDate' => '2025-01-01',
        'contacts' => [['id' => 900, 'role' => 'Primary', 'namedOnLease' => true]], ...$overrides];
}

test('tells the owner and the tenant once, and records the lease', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01')];

    $this->artisan('leases:notify-expiring')->assertSuccessful();
    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->owner->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentTo($this->owner->user, fn (LeaseExpiringNotification $n) => $n->notice['role'] === 'owner' && $n->notice['account_id'] === $this->owner->id);

    $record = LeaseExpiryNotice::sole();
    expect($record->propertyware_lease_id)->toBe(55)
        ->and($record->recipients)->toBe(2)
        ->and($record->notified_at)->not->toBeNull();
});

test('asks for leases ending between today and 90 days out', function () {
    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/leases')
        && $request->data()['endDateStart'] === '2026-10-03'
        && $request->data()['endDateEnd'] === '2027-01-01');
});

test('an extended lease is reminded again before its new end date', function () {
    Notification::fake();
    LeaseExpiryNotice::create(['propertyware_lease_id' => 55, 'end_date' => '2026-06-01', 'notified_at' => now()->subYear(), 'recipients' => 2]);
    $this->leases = [activeLease(55, '2027-01-01')];

    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
});

test('the first run tells only leases 80 to 90 days out and quietly records the rest', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01'), activeLease(56, '2026-11-01')];

    $this->artisan('leases:notify-expiring', ['--seed' => true])->assertSuccessful();

    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
    expect(LeaseExpiryNotice::where('propertyware_lease_id', 56)->sole()->notified_at)->toBeNull();

    // Recorded, so the normal daily run leaves it alone too.
    $this->artisan('leases:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
});

test('when Propertyware fails nothing is recorded, so tomorrow tries again', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01')];
    $this->buildingsFail = true;

    $this->artisan('leases:notify-expiring')->assertFailed();

    Notification::assertNothingSent();
    expect(LeaseExpiryNotice::count())->toBe(0);
});

test('a lease whose recipients cannot be read is skipped for today while the others go out', function () {
    Notification::fake();
    Log::spy();
    $this->leases = [activeLease(55, '2027-01-01'), activeLease(56, '2026-12-20')];
    $this->ownerContactFailures = 3; // every attempt of the first lookup, retries included

    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->owner->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentToTimes($this->tenant->user, LeaseExpiringNotification::class, 1);
    Notification::assertSentTo($this->tenant->user, fn (LeaseExpiringNotification $n) => $n->notice['lease_id'] === 56);
    expect(LeaseExpiryNotice::pluck('propertyware_lease_id')->all())->toBe([56]);
    Log::shouldHaveReceived('warning')->once();

    // Tomorrow the skipped lease is tried again.
    $this->artisan('leases:notify-expiring')->assertSuccessful();
    Notification::assertSentTo($this->tenant->user, fn (LeaseExpiringNotification $n) => $n->notice['lease_id'] === 55);
});

test('someone listed twice for a lease gets one copy', function () {
    Notification::fake();
    $this->leases = [activeLease(55, '2027-01-01')];
    $owner = $this->owner->load('user');

    $this->mock(LeaseExpiryRecipients::class, function ($mock) use ($owner) {
        $mock->shouldReceive('owners')->andReturn(collect([$owner, $owner]));
        $mock->shouldReceive('tenants')->andReturn(collect([$owner]));
    });

    $this->artisan('leases:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($this->owner->user, LeaseExpiringNotification::class, 1);
    expect(LeaseExpiryNotice::sole()->recipients)->toBe(1);
});

test('the owner notice holds no tenant names, lease name, emails or phone numbers', function () {
    $tenantDetails = ['Jonathan', 'Quincyworth', 'Marisol', 'Quincyworth, Jonathan & Marisol', 'jonathan.q@example.com', 'marisol.q@example.com', '512-555-0142', '5125550199'];
    $this->leases = [activeLease(55, '2027-01-01', [
        'name' => 'Quincyworth, Jonathan & Marisol',
        'contacts' => [
            ['id' => 900, 'role' => 'Primary', 'namedOnLease' => true, 'firstName' => 'Jonathan', 'lastName' => 'Quincyworth', 'email' => 'jonathan.q@example.com', 'mobilePhone' => '512-555-0142'],
            ['id' => 901, 'role' => 'Secondary', 'namedOnLease' => true, 'firstName' => 'Marisol', 'lastName' => 'Quincyworth', 'email' => 'marisol.q@example.com', 'homePhone' => '5125550199'],
        ],
    ])];
    $this->tenantContact = ['id' => '900', 'type' => 'TENANT', 'firstName' => 'Jonathan', 'lastName' => 'Quincyworth', 'email' => 'jonathan.q@example.com', 'mobilePhone' => '512-555-0142'];

    $this->artisan('leases:notify-expiring')->assertSuccessful();

    $stored = json_encode($this->owner->user->notifications()->sole()->data);

    expect($stored)->toContain('7734 Park Falls');
    foreach ($tenantDetails as $detail) {
        expect($stored)->not->toContain($detail);
    }
});

test('runs every morning at nine, Houston time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'leases:notify-expiring'));

    expect($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('America/Chicago')
        ->and($event->withoutOverlapping)->toBeTrue();
});
