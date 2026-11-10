<?php

use App\PortalSso\PortalAuthException;
use App\PortalSso\PortalClient;
use App\PortalSso\PortalRevokedException;
use App\PortalSso\PortalUnavailableException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'portal.enabled' => true,
        'portal.issuer' => 'https://login.texasrenters.com',
        'portal.client_id' => 'client-123',
        'portal.client_secret' => 'secret-xyz',
        'portal.app_slug' => 'tx-client',
        'app.url' => 'https://app.texasrenters.com',
    ]);
});

test('the authorization url carries the client, pkce s256, state and the callback', function () {
    $url = app(PortalClient::class)->authorizationUrl('state-1', 'challenge-1');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    expect($url)->toStartWith('https://login.texasrenters.com/oauth/authorize?')
        ->and(array_intersect_key($q, array_flip(['client_id', 'response_type', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'])))
        ->toBe([
            'client_id' => 'client-123',
            'response_type' => 'code',
            'redirect_uri' => 'https://app.texasrenters.com/auth/portal/callback',
            'scope' => 'openid profile email apps',
            'state' => 'state-1',
            'code_challenge' => 'challenge-1',
            'code_challenge_method' => 'S256',
        ]);
});

test('exchanging a code posts the verifier and client credentials', function () {
    Http::fake(['login.texasrenters.com/oauth/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 600])]);

    $tokens = app(PortalClient::class)->exchangeCode('code-1', 'verifier-1');

    expect($tokens['access_token'])->toBe('a')->and($tokens['refresh_token'])->toBe('r');
    Http::assertSent(fn ($r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-1'
        && $r['code_verifier'] === 'verifier-1' && $r['client_id'] === 'client-123');
});

test('a refused code exchange throws an auth exception', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(PortalClient::class)->exchangeCode('bad', 'v');
})->throws(PortalAuthException::class);

test('a refused refresh means revoked', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(PortalClient::class)->refresh('r');
})->throws(PortalRevokedException::class);

test('a portal outage during refresh means unavailable, not revoked', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    app(PortalClient::class)->refresh('r');
})->throws(PortalUnavailableException::class);

test('refresh returns new tokens', function () {
    Http::fake(['*' => Http::response(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 600])]);

    expect(app(PortalClient::class)->refresh('r1')['refresh_token'])->toBe('r2');
    Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
});

test('userinfo sends the bearer token and returns the claims', function () {
    Http::fake(['login.texasrenters.com/oauth/userinfo' => Http::response(['sub' => 'uuid-1', 'email' => 'maria@texasrenters.com', 'apps' => ['tx-client']])]);

    expect(app(PortalClient::class)->userinfo('tok')['sub'])->toBe('uuid-1');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));
});

test('a userinfo 401 means revoked', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_token'], 401)]);

    app(PortalClient::class)->userinfo('tok');
})->throws(PortalRevokedException::class);
