<?php

use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // Server rendering is an HTTP call too, which would muddy what reached Propertyware.
    config(['services.admin_emails' => ['staff@texasrenters.com'], 'inertia.ssr.enabled' => false]);
    $this->admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
});

test('searching an address shows its owners and tenants and who is on the portal', function () {
    Http::fake([
        '*/buildings*' => Http::response([
            ['id' => 10, 'portfolioID' => 7, 'leaseID' => 50, 'name' => 'Tambourine', 'address' => ['address' => '12009 Tambourine Dr', 'city' => 'Houston', 'stateRegion' => 'TX', 'postalCode' => '77070']],
            ['id' => 11, 'portfolioID' => 8, 'address' => ['address' => '1 Other St', 'city' => 'Houston']],
        ]),
        '*/units*' => Http::response([]),
        '*/leases/50' => Http::response(['id' => 50, 'contacts' => [['id' => 900, 'firstName' => 'Yeni', 'lastName' => 'Vega', 'email' => 'yeni@example.com']]]),
        '*/portfolios/7' => Http::response(['id' => 7, 'owners' => [['id' => 800, 'firstName' => 'Olga', 'lastName' => 'Owner', 'email' => 'olga@example.com']]]),
    ]);
    $tenant = User::factory()->create(['email' => 'yeni@example.com']);
    ClientAccount::factory()->resident()->for($tenant)->create(['propertyware_id' => '900']);
    ClientInvitation::factory()->owner()->create(['propertyware_id' => '800']);

    $this->actingAs($this->admin)->get(route('admin.properties', ['search' => 'tambourine']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/PropertyFinder')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('found.total', 1)
                ->where('found.results.0.address', '12009 Tambourine Dr Houston, TX 77070')
                ->where('found.results.0.residents.0.name', 'Yeni Vega')
                ->where('found.results.0.residents.0.linkedUserId', $tenant->id)
                ->where('found.results.0.owners.0.name', 'Olga Owner')
                ->where('found.results.0.owners.0.pendingInvitation', true)));
});

test('a search too short to be useful does not reach propertyware', function () {
    Http::fake();

    $this->actingAs($this->admin)->get(route('admin.properties', ['search' => 'ab']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('found', null)));

    Http::assertNothingSent();
});

test('propertyware being down is reported rather than failing the page', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $this->actingAs($this->admin)->get(route('admin.properties', ['search' => 'tambourine']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('found.error', 'Propertyware could not be reached. Try again in a moment.')));
});

test('clients cannot search properties', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.properties', ['search' => 'elm']))->assertForbidden();
});
