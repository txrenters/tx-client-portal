<?php

use App\Enums\ClientRole;
use App\Models\User;
use App\Notifications\LeaseExpiringNotification;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoReset;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false, 'services.chat_hub.url' => null]);
});

function noteFor(User $user): void
{
    $user->notifyNow(new LeaseExpiringNotification(['type' => 'lease_expiring', 'title' => 'x']), ['database']);
}

test('reset clears the demo users notifications but not a real users', function () {
    [$owner] = demoLogin(ClientRole::Owner);
    $real = User::factory()->create();
    noteFor($owner);
    noteFor($real);

    app(DemoReset::class)->handle();

    expect($real->notifications()->count())->toBe(1)
        ->and($owner->notifications->map(fn ($n) => $n->data['title'])->all())->not->toContain('x');
});

test('reset seeds the demo bell with the lease notices and sends no push', function () {
    Http::fake(['*' => Http::response([])]);
    [$ownerUser, $ownerAccount] = demoLogin(ClientRole::Owner);
    [$tenantUser, $tenantAccount] = demoLogin(ClientRole::Resident);
    noteFor($ownerUser);

    app(DemoReset::class)->handle();
    app(DemoReset::class)->handle();

    $end = collect((new DemoData)->leases())->first()['endDate'];

    expect($ownerUser->notifications()->count())->toBe(1)
        ->and($tenantUser->notifications()->count())->toBe(1);

    $owner = $ownerUser->notifications()->sole()->data;
    $tenant = $tenantUser->notifications()->sole()->data;
    expect($owner)->toMatchArray(['type' => 'lease_expiring', 'role' => 'owner', 'account_id' => $ownerAccount->id, 'lease_id' => DemoData::LEASE, 'building_id' => DemoData::HOME_LEASED, 'end_date' => $end])
        ->and($tenant)->toMatchArray(['type' => 'lease_expiring', 'role' => 'resident', 'account_id' => $tenantAccount->id, 'lease_id' => DemoData::LEASE, 'end_date' => $end]);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'exp.host'));

    Sanctum::actingAs($ownerUser);
    $this->getJson(route('api.v1.accounts.notifications.index', $ownerAccount))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1);
    Sanctum::actingAs($tenantUser);
    $this->getJson(route('api.v1.accounts.notifications.index', $tenantAccount))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1);
});
