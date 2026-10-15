<?php

use App\Http\Middleware\DemoMode;
use App\Http\Middleware\EnsureLeaseIsNotTerminated;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\GuardRequests;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SyncPropertywareAccounts;
use App\Http\Middleware\TrackLastSeen;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Every request, matched to a route or not: refuse blocked addresses
        // and scanners first, and send the browser protections on the way out.
        $middleware->append([GuardRequests::class, SecurityHeaders::class]);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Sorted after the route's auth middleware, so an app token has been
        // resolved to its user before the suspension or the lease is checked.
        $middleware->appendToGroup('api', [EnsureUserIsNotSuspended::class, EnsureLeaseIsNotTerminated::class, TrackLastSeen::class, DemoMode::class]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureUserIsNotSuspended::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureLeaseIsNotTerminated::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, TrackLastSeen::class);

        $middleware->web(append: [
            HandleAppearance::class,
            EnsureUserIsNotSuspended::class,
            EnsureLeaseIsNotTerminated::class,
            TrackLastSeen::class,
            // Before anything reads Propertyware for the page: an App Review login sees made-up records.
            DemoMode::class,
            SyncPropertywareAccounts::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (PropertywareException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('Propertyware data is unavailable right now. Please try again in a moment.')], 503)
            : null);
    })->create();
