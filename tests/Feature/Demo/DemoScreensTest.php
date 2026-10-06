<?php

use App\Enums\ClientRole;
use App\Services\Demo\DemoData;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Scheduling stays real for the demo; here it is faked like any test would.
    config(['services.scheduling.url' => 'https://scheduling.test', 'services.scheduling.token' => 't', 'services.scheduling.page' => 'texas-renters']);
    Http::fake(['scheduling.test/*' => Http::response(['data' => []])]);
});

/**
 * Every call the request made to a demo host was answered: a quiet 404 on a
 * deferred prop would otherwise pass as "unavailable" with a 200 page.
 */
function expectDemoAnsweredEverything(): void
{
    $failed = Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_HOST), '.demo.invalid') && $pair[1]->status() >= 400)
        ->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())
        ->values()
        ->all();

    expect($failed)->toBe([]);
}

/**
 * Statements and referrals have nothing deferred; reloading "only" nothing
 * would fail on the assertion helper, not on the page.
 */
function loadDeferredPropsIfAny(AssertableInertia $page): AssertableInertia
{
    $deferred = (fn () => $this->deferredProps)->call($page);

    return $deferred === [] ? $page : $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload);
}

dataset('owner pages', [
    'dashboard' => ['owner.dashboard', []],
    'portfolio' => ['owner.portfolio', []],
    'properties' => ['owner.properties.index', []],
    'leased home' => ['owner.properties.show', ['property' => DemoData::HOME_LEASED]],
    'vacant home' => ['owner.properties.show', ['property' => DemoData::HOME_VACANT]],
    'financials' => ['owner.financials', []],
    'statements' => ['owner.statements', []],
    'maintenance' => ['owner.maintenance.index', []],
    'work order waiting' => ['owner.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_APPROVAL]],
    'work order done' => ['owner.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'documents' => ['owner.documents', []],
    'conversations' => ['owner.conversations.index', []],
    'messages' => ['owner.messages.index', []],
    'a message thread' => ['owner.messages.index', ['thread' => DemoData::OWNER_THREAD]],
    'meetings' => ['owner.meetings.index', []],
    'referrals' => ['owner.referrals.index', []],
]);

dataset('tenant pages', [
    'dashboard' => ['resident.dashboard', []],
    'lease' => ['resident.lease', []],
    'property' => ['resident.property', []],
    'maintenance' => ['resident.maintenance.index', []],
    'work order' => ['resident.maintenance.show', ['workOrder' => DemoData::WORK_ORDER_GARAGE]],
    'documents' => ['resident.documents', []],
    'conversations' => ['resident.conversations.index', []],
    'messages' => ['resident.messages.index', []],
    'a message thread' => ['resident.messages.index', ['thread' => DemoData::TENANT_THREAD]],
    'referrals' => ['resident.referrals.index', []],
]);

test('the demo owner sees every web page with its deferred data', function (string $route, array $parameters) {
    [$user] = demoLogin(ClientRole::Owner);

    $this->actingAs($user)->get(route($route, $parameters))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => loadDeferredPropsIfAny($page));

    expectDemoAnsweredEverything();
})->with('owner pages');

test('the demo tenant sees every web page with its deferred data', function (string $route, array $parameters) {
    [$user] = demoLogin(ClientRole::Resident);

    $this->actingAs($user)->get(route($route, $parameters))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => loadDeferredPropsIfAny($page));

    expectDemoAnsweredEverything();
})->with('tenant pages');

test('demo documents open as pdfs on the web', function (ClientRole $role, string $route, array $parameters) {
    [$user] = demoLogin($role);

    $response = $this->actingAs($user)->get(route($route, $parameters))->assertOk();

    expect($response->streamedContent())->toStartWith('%PDF-1.4');
})->with([
    'owner statement' => [ClientRole::Owner, 'owner.documents.download', ['document' => DemoData::DOC_STATEMENT]],
    'owner invoice on a work order' => [ClientRole::Owner, 'owner.maintenance.documents.download', ['workOrder' => DemoData::WORK_ORDER_DONE, 'document' => DemoData::DOC_INVOICE]],
    'tenant lease' => [ClientRole::Resident, 'resident.documents.download', ['document' => DemoData::DOC_LEASE]],
]);

dataset('owner api', [
    'dashboard' => ['dashboard', []], 'portfolio' => ['portfolio', []], 'financials' => ['financials', []],
    'ledger' => ['ledger', []], 'properties' => ['properties.index', []],
    'leased home' => ['properties.show', ['property' => DemoData::HOME_LEASED]],
    'approvals' => ['approvals.index', []], 'meetings' => ['meetings.index', []], 'contact details' => ['contact-details', []],
    'messages' => ['messages.index', []], 'message thread' => ['messages.show', ['thread' => DemoData::OWNER_THREAD]],
    'referrals' => ['referrals.index', []], 'conversations' => ['conversations.index', []],
    'maintenance' => ['maintenance.index', []], 'request options' => ['maintenance.request-options', []],
    'work order' => ['maintenance.show', ['workOrder' => DemoData::WORK_ORDER_APPROVAL]],
    'work order documents' => ['maintenance.documents.index', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'work order conversations' => ['maintenance.conversations.index', ['workOrder' => DemoData::WORK_ORDER_DONE]],
    'documents' => ['documents.index', []], 'upload targets' => ['documents.upload-targets', []],
    'document' => ['documents.show', ['document' => DemoData::DOC_STATEMENT]],
]);

dataset('tenant api', [
    'dashboard' => ['dashboard', []], 'lease' => ['lease', []], 'payments' => ['payments', []],
    // No meetings endpoint here: booking is owner-only (it 404s for tenants).
    'contact details' => ['contact-details', []],
    'messages' => ['messages.index', []], 'message thread' => ['messages.show', ['thread' => DemoData::TENANT_THREAD]],
    'referrals' => ['referrals.index', []], 'conversations' => ['conversations.index', []],
    'maintenance' => ['maintenance.index', []], 'request options' => ['maintenance.request-options', []],
    'work order' => ['maintenance.show', ['workOrder' => DemoData::WORK_ORDER_GARAGE]],
    'documents' => ['documents.index', []], 'document' => ['documents.show', ['document' => DemoData::DOC_LEASE]],
]);

test('the demo owner\'s app gets every endpoint', function (string $route, array $parameters) {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->get(route('api.v1.accounts.'.$route, ['account' => $account, ...$parameters]), ['Accept' => 'application/json'])->assertOk();

    expectDemoAnsweredEverything();
})->with('owner api');

test('the demo tenant\'s app gets every endpoint', function (string $route, array $parameters) {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->get(route('api.v1.accounts.'.$route, ['account' => $account, ...$parameters]), ['Accept' => 'application/json'])->assertOk();

    expectDemoAnsweredEverything();
})->with('tenant api');

test('the owner\'s app shows the work order waiting for approval', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.approvals.index', $account))->assertOk()
        ->assertJsonFragment(['id' => (string) DemoData::WORK_ORDER_APPROVAL]);
});

test('the tenant\'s app shows rent due and the lease ending soon', function () {
    [$user, $account] = demoLogin(ClientRole::Resident);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.lease', $account))->assertOk()
        ->assertJsonFragment(['endDate' => today('America/Chicago')->addDays(DemoData::LEASE_DAYS_LEFT)->toDateString()]);
});
