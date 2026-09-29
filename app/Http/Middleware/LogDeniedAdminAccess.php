<?php

namespace App\Http\Middleware;

use App\Services\Security\IpGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes a signed-in client being turned away from the admin area. Sits just
 * outside the admin gate, so it sees the gate's refusal as a 403.
 */
class LogDeniedAdminAccess
{
    public function __construct(private IpGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 403 && $request->user() !== null) {
            $this->guard->record($request, 'admin_denied', ['method' => $request->method()]);
        }

        return $response;
    }
}
