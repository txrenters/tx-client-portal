<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\PropertywareClient;
use Illuminate\Support\Facades\Http;

test('shows all named portfolios with their own buildings and retains empty portfolios', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    $portfolios = collect(range(1, 94))->map(fn (int $id): array => ['id' => $id, 'name' => 'Portfolio '.$id])->all();
    $portfolios[0]['name'] = 'BX - Che, Hongxia';
    $portfolios[1]['name'] = '$BX - Chen, Hui';
    $building = ['id' => 7, 'portfolioID' => 1, 'leaseID' => 55, 'address' => ['address' => '12 Elm St']];
    app()->instance(PropertywareClient::class, new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system'));
    Http::preventStrayRequests();
    Http::fake([
        'https://propertyware.test/contacts/500' => Http::response(['id' => 500, 'type' => 'OWNER', 'attachedPortfolios' => $portfolios]),
        'https://propertyware.test/units*' => Http::response([]),
        'https://propertyware.test/buildings?*' => Http::response([
            $building,
            ['id' => 999, 'portfolioID' => 999, 'address' => ['address' => 'Unrelated building']],
        ]),
    ]);
    $this->actingAs($user);

    partialGet(route('owner.portfolio'), 'owner/Portfolio', 'portfolio')
        ->assertOk()
        ->assertJsonCount(94, 'props.portfolio.portfolios')
        ->assertJsonPath('props.portfolio.portfolios.0.name', 'BX - Che, Hongxia')
        ->assertJsonPath('props.portfolio.portfolios.0.properties.0.address', '12 Elm St')
        ->assertJsonPath('props.portfolio.portfolios.1.name', '$BX - Chen, Hui')
        ->assertJsonPath('props.portfolio.portfolios.1.properties', [])
        ->assertJsonPath('props.portfolio.occupancy.total', 1)
        ->assertDontSee('Unrelated building');

    partialGet(route('owner.dashboard'), 'owner/Dashboard', 'portfolio')
        ->assertOk()
        ->assertJsonPath('props.portfolio.portfolioCount', 94)
        ->assertJsonPath('props.portfolio.propertyCount', 1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/workorders') || str_contains($request->url(), '/buildings/'));
    Http::assertSentCount(3);
});
