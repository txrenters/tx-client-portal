<?php

namespace App\Http\Middleware;

use App\Services\Security\IpGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first thing every request meets: blocked addresses are refused, and
 * requests for files only a scanner looks for (/.env, /wp-login.php) are
 * logged and answered with a plain 404 before any of the app runs.
 *
 * Global rather than per route, because scanner paths match no route; and
 * after TrustProxies, so the address is the visitor's and not the proxy's.
 */
class GuardRequests
{
    public function __construct(private IpGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->guard->isBlocked($request->ip())) {
            $this->guard->refused($request);

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => 'Access denied.'], 403)
                : response('Access denied.', 403, ['Content-Type' => 'text/plain']);
        }

        if ($this->guard->isProbe($request)) {
            $this->guard->record($request, 'probe');

            return response('Not found.', 404, ['Content-Type' => 'text/plain']);
        }

        return $next($request);
    }
}
