<?php

use App\Services\Admin\IntegrationHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    config([
        'services.propertyware.client_id' => 'id',
        'services.propertyware.client_secret' => 'secret',
        'services.propertyware.system_id' => 'system',
        'services.propertyware.base_url' => 'https://pw.test/v1',
        'services.chat_hub.url' => 'https://hub.test',
        'services.chat_hub.token' => 'token',
    ]);
});

function health(): IntegrationHealth
{
    return app(IntegrationHealth::class);
}

function statuses(): array
{
    return collect(health()->all(fresh: true))->pluck('status', 'key')->all();
}

test('reports a service that answers as healthy', function () {
    Http::fake(['*' => Http::response([])]);

    expect(statuses())->toMatchArray([
        'propertyware' => 'ok',
        'chat_hub' => 'ok',
        'database' => 'ok',
    ]);
});

test('reports the reason a service is failing rather than just that it is', function () {
    Http::fake(['*/portfolios*' => Http::response([], 503), '*' => Http::response([])]);

    $propertyware = collect(health()->all(fresh: true))->firstWhere('key', 'propertyware');

    expect($propertyware['status'])->toBe('failing')
        ->and($propertyware['detail'])->toContain('503');
});

test('separates a service that is not set up from one that is broken', function () {
    config(['services.chat_hub.url' => null, 'services.chat_hub.token' => null]);
    Http::fake(['*' => Http::response([])]);

    $hub = collect(health()->all(fresh: true))->firstWhere('key', 'chat_hub');

    expect($hub['status'])->toBe('not_configured')
        ->and($hub['detail'])->toContain('No credentials');
});

test('says when credentials exist but there is no read to prove them', function () {
    config([
        'services.propertyware.soap.url' => 'https://pw.test/soap',
        'services.propertyware.soap.username' => 'user',
        'services.propertyware.soap.password' => 'secret',
    ]);
    Http::fake(['*' => Http::response([])]);

    $soap = collect(health()->all(fresh: true))->firstWhere('key', 'propertyware_soap');

    // SOAP's only operation is a write, so a health check must not call it.
    expect($soap['status'])->toBe('configured');
});

test('checking does not write to any service', function () {
    Http::fake(['*' => Http::response([])]);

    health()->all(fresh: true);

    Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
});

test('results are cached so a refresh does not hammer four services', function () {
    Http::fake(['*' => Http::response([])]);

    health()->all(fresh: true);
    $afterFirst = count(Http::recorded());
    health()->all();

    expect(count(Http::recorded()))->toBe($afterFirst);
});

test('counts failed and pending background jobs', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    expect(health()->queue())->toMatchArray(['failed' => 1, 'pending' => 0]);
});
