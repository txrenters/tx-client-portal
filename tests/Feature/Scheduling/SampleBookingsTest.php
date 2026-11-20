<?php

use App\Providers\AppServiceProvider;
use App\Services\Scheduling\SchedulingClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.scheduling.url' => 'https://scheduling.test',
        'services.scheduling.token' => 'secret',
        'services.scheduling.sample_bookings' => true,
    ]);
});

/** Build the scheduling client the way the app does, in the given environment. */
function schedulingClientIn(string $environment): SchedulingClient
{
    app()->instance('env', $environment);
    app()->forgetInstance(SchedulingClient::class);
    (new AppServiceProvider(app()))->register();

    return app(SchedulingClient::class);
}

test('a local copy shows made-up meetings, counted from today, without asking coresched', function () {
    Http::fake();
    $this->travelTo('2026-11-21 15:00:00');

    $upcoming = schedulingClientIn('local')->upcoming('owner@example.com');

    expect($upcoming)->toHaveCount(5)
        // Two hours ago, in Houston.
        ->and($upcoming[0]['startsAt'])->toBe('2026-11-21T13:00:00+00:00')
        // Tomorrow at 10:00 in Houston.
        ->and($upcoming[1]['startsAt'])->toBe('2026-11-22T16:00:00+00:00')
        ->and($upcoming[1]['name'])->toBe('Accounting Team Meeting - Phone Call');

    Http::assertNothingSent();
});

test('the switch is ignored outside a local copy', function () {
    Http::fake(['*/bookings*' => Http::response(['data' => []])]);

    expect(schedulingClientIn('production')->upcoming('owner@example.com'))->toBe([]);

    Http::assertSentCount(1);
});
