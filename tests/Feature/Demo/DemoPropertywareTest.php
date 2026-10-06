<?php

use App\Http\Middleware\DemoMode;
use App\Models\DemoRecord;
use App\Services\Demo\DemoData;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\PropertywareSoap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // As in production: a real client already resolved before the request turns demo.
    app(PropertywareClient::class);
    DemoMode::enter();
    $this->pw = app(PropertywareClient::class);
});

test('reads the demo owner and the homes in their portfolio', function () {
    expect($this->pw->get('contacts/'.DemoData::OWNER_CONTACT)['type'])->toBe('OWNER')
        ->and(collect($this->pw->listAll('buildings', ['portfolioID' => DemoData::PORTFOLIO]))->pluck('id')->all())
        ->toBe([DemoData::HOME_LEASED, DemoData::HOME_VACANT]);
});

test('only ever calls the demo host', function () {
    $this->pw->get('leases/'.DemoData::LEASE);

    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'propertyware.demo.invalid');
    Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) !== 'propertyware.demo.invalid');
});

test('filters collections the way Propertyware does', function () {
    expect($this->pw->listAll('workorders', ['buildingID' => DemoData::HOME_VACANT]))->toHaveCount(1)
        ->and($this->pw->listAll('leases/adjustments', ['leaseID' => 1]))->toBe([])
        ->and($this->pw->listAll('docs', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE]))->toHaveCount(1)
        ->and($this->pw->listAll('accounting/generalledger', [
            'portfolioID' => DemoData::PORTFOLIO,
            'postDateStart' => now('America/Chicago')->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'postDateEnd' => now('America/Chicago')->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]))->toHaveCount(2)
        ->and($this->pw->listAll('bills', ['portfolioID' => DemoData::PORTFOLIO]))->toHaveCount(2)
        ->and($this->pw->listAll('bills', ['portfolioID' => 1]))->toBe([]);
});

test('pages through a collection like Propertyware', function () {
    expect($this->pw->list('workorders', ['portfolioID' => DemoData::PORTFOLIO], limit: 2, offset: 2))->toHaveCount(2)
        ->and($this->pw->list('workorders', ['portfolioID' => DemoData::PORTFOLIO], limit: 2, offset: 4))->toBe([]);
});

test('reads several records at once', function () {
    $homes = $this->pw->getMany(['a' => 'buildings/'.DemoData::HOME_LEASED, 'b' => 'buildings/'.DemoData::HOME_VACANT]);

    expect($homes['a']['leaseID'])->toBe(DemoData::LEASE)->and($homes['b']['leaseID'])->toBeNull();
});

test('answers a path it does not know with 404, so a gap fails loudly', function () {
    $this->pw->get('contacts/123');
})->throws(PropertywareException::class, 'Propertyware returned 404');

test('keeps a created work order for the demo and lists it afterwards', function () {
    $created = $this->pw->create('workorders', ['buildingID' => DemoData::HOME_LEASED, 'description' => 'Smoke alarm chirping', 'category' => 'General', 'publishToTenantPortal' => true]);

    expect((int) $created['id'])->toBeGreaterThanOrEqual(DemoRecord::FIRST_ID)
        ->and(collect($this->pw->listAll('workorders', ['buildingID' => DemoData::HOME_LEASED]))->pluck('description'))->toContain('Smoke alarm chirping')
        ->and($this->pw->get('workorders/'.$created['id'])['status'])->toBe('Open');
});

test('keeps an uploaded document, its description and its file', function () {
    $created = $this->pw->upload('docs', '%PDF-1.4 mine', 'Renters insurance.pdf', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE, 'publishToOwnerPortal' => false, 'publishToTenantPortal' => true]);
    $this->pw->update('docs/'.$created['id'], ['fileName' => 'Renters insurance.pdf', 'description' => 'Policy for this year']);

    $listed = collect($this->pw->listAll('docs', ['entityType' => 'LEASE', 'entityId' => DemoData::LEASE]))->firstWhere('id', $created['id']);

    expect($listed)->toMatchArray(['fileName' => 'Renters insurance.pdf', 'description' => 'Policy for this year', 'publishToTenantPortal' => true])
        ->and($this->pw->download('docs/'.$created['id'].'/download')->body())->toBe('%PDF-1.4 mine');
});

test('downloads a made-up document as a pdf', function () {
    $response = $this->pw->download('docs/'.DemoData::DOC_LEASE.'/download');

    expect($response->header('Content-Type'))->toBe('application/pdf')->and($response->body())->toStartWith('%PDF-1.4');
});

test('approving a work order over SOAP does nothing', function () {
    app(PropertywareSoap::class)->approveWorkOrder(DemoData::WORK_ORDER_APPROVAL, today()->toDateString(), 'ok');

    expect(app(PropertywareSoap::class)->isConfigured())->toBeTrue();
});

test('keeps the demo cache apart from the real one', function () {
    expect(Cache::getDefaultDriver())->toBe(DemoMode::cacheStore())
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('services.propertyware.discover_accounts'))->toBeFalse();
});
