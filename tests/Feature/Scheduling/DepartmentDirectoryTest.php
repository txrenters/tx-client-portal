<?php

use App\Services\Scheduling\MeetingCatalog;
use Illuminate\Support\Facades\Http;

/** A coresched booking page with these Inertia props, as it is served. */
function bookingPage(array $props): string
{
    return '<html><body><script data-page="app" type="application/json">'
        .json_encode(['component' => 'book/Page', 'props' => $props])
        .'</script></body></html>';
}

beforeEach(function () {
    config([
        'services.scheduling.url' => 'https://scheduling.test',
        'services.scheduling.token' => 'secret',
        'services.scheduling.page' => 'texas-renters',
        'services.scheduling.booking_url' => 'https://scheduling.test/book/texas-renters/departments/abc',
    ]);
});

test('meetings take their departments from the departments page', function () {
    Http::fake([
        '*/api/v1/organizations/*/event-types' => Http::response(['data' => [
            ['slug' => 'accounting-team-meeting', 'name' => 'Accounting Team Meeting - Phone Call'],
            ['slug' => 'leasing-team-meeting-phone-call', 'name' => 'Leasing Team Meeting - Phone Call'],
            ['slug' => 'new-to-market-meeting-phone-call', 'name' => 'New To Market Meeting - Phone Call'],
        ]]),
        'scheduling.test/book/texas-renters/departments/abc' => Http::response(bookingPage(['departments' => [
            ['name' => 'Accounting Department', 'url' => 'https://scheduling.test/book/texas-renters/accounting?from=abc'],
            ['name' => 'Leasing Department', 'url' => 'https://scheduling.test/book/texas-renters/leasing?from=abc'],
        ]])),
        'scheduling.test/book/texas-renters/accounting*' => Http::response(bookingPage(['eventTypes' => [
            ['slug' => 'accounting-team-meeting'],
            // Also listed by a second department.
            ['slug' => 'leasing-team-meeting-phone-call'],
        ]])),
        'scheduling.test/book/texas-renters/leasing*' => Http::response(bookingPage(['eventTypes' => [
            ['slug' => 'leasing-team-meeting-phone-call'],
        ]])),
    ]);

    $departments = collect(app(MeetingCatalog::class)->forClients())->pluck('departments', 'slug');

    expect($departments['accounting-team-meeting'])->toBe(['Accounting Department'])
        ->and($departments['leasing-team-meeting-phone-call'])->toBe(['Accounting Department', 'Leasing Department'])
        // On no department's page: shown under "Other meetings".
        ->and($departments['new-to-market-meeting-phone-call'])->toBe([]);
});

test('the departments are read once and remembered', function () {
    Http::fake([
        '*/api/v1/organizations/*/event-types' => Http::response(['data' => [['slug' => 'accounting-team-meeting']]]),
        '*/departments/abc' => Http::response(bookingPage(['departments' => []])),
    ]);

    app(MeetingCatalog::class)->forClients();
    app(MeetingCatalog::class)->forClients();

    Http::assertSentCount(3);
});

test('an unreadable departments page leaves the meetings ungrouped', function () {
    Http::fake([
        '*/api/v1/organizations/*/event-types' => Http::response(['data' => [['slug' => 'accounting-team-meeting']]]),
        '*/departments/abc' => Http::response('<html>Down for maintenance</html>', 503),
    ]);

    expect(app(MeetingCatalog::class)->forClients())
        ->toBe([['slug' => 'accounting-team-meeting', 'departments' => []]]);
});

test('departments coresched sends itself are kept, and the page is not read', function () {
    Http::fake([
        '*/api/v1/organizations/*/event-types' => Http::response(['data' => [
            ['slug' => 'accounting-team-meeting', 'departments' => ['Accounting']],
        ]]),
    ]);

    expect(app(MeetingCatalog::class)->forClients()[0]['departments'])->toBe(['Accounting']);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/departments/'));
});

test('department links to another site are not followed', function () {
    Http::fake([
        '*/api/v1/organizations/*/event-types' => Http::response(['data' => [['slug' => 'accounting-team-meeting']]]),
        '*/departments/abc' => Http::response(bookingPage(['departments' => [
            ['name' => 'Elsewhere', 'url' => 'https://internal.example/admin'],
            ['name' => 'Plain http', 'url' => 'http://scheduling.test/book/texas-renters/accounting'],
        ]])),
    ]);

    expect(app(MeetingCatalog::class)->forClients()[0]['departments'])->toBe([]);

    Http::assertSentCount(2);
});
