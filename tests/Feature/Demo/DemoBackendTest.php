<?php

use App\Http\Middleware\DemoMode;
use App\Services\Demo\DemoBackend;
use App\Services\Propertyware\PropertywareClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('a real outside host is refused during a demo request, even from a client built before it', function () {
    config(['services.propertyware.base_url' => 'https://pw.real.test']);
    app(PropertywareClient::class);

    DemoMode::enter();
    $response = Http::get('https://pw.real.test/contacts/1');

    expect($response->status())->toBe(404)
        ->and($response->json('message'))->toBe('Not available in the demo.');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pw.real.test/contacts/1');
});

test('the real hosts of every outside system are refused', function () {
    config([
        'services.chat_hub.url' => 'https://hub.real.test/api/v1',
        'services.tx_cma.url' => 'https://cma.real.test',
        'services.leads.webhook_url' => 'https://leads.real.test/referrals',
        'services.expo.url' => 'https://expo.real.test',
    ]);

    DemoMode::enter();

    foreach (['hub', 'cma', 'leads', 'expo'] as $host) {
        expect(Http::get("https://{$host}.real.test/x")->status())->toBe(404);
    }
});

test('scheduling stays real', function () {
    config(['services.scheduling.url' => 'https://scheduling.test']);
    DemoMode::enter();

    expect(app(DemoBackend::class)->respond(new Request(new GuzzleHttp\Psr7\Request('GET', 'https://scheduling.test/api/x'))))->toBeNull();
});

test('listings, referrals and pushes are answered without leaving the portal', function (string $host, array $body) {
    DemoMode::enter();
    $response = Http::post("https://{$host}/anything");

    expect($response->status())->toBe(200)->and($response->json())->toBe($body);
})->with([
    'listings' => [DemoBackend::LISTINGS, ['match_type' => 'none']],
    'leads' => [DemoBackend::LEADS, ['ok' => true]],
    'expo' => [DemoBackend::EXPO, ['data' => []]],
]);
