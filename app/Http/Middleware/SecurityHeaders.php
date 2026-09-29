<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections on every response: no framing by other sites, no
 * guessing content types, no leaking full URLs to other sites, no camera or
 * microphone, and HTTPS only once a visit has arrived over HTTPS.
 *
 * A response that already set one of these (a document preview, say) keeps
 * its own value.
 */
class SecurityHeaders
{
    /** A year, the value browsers' preload lists expect. */
    private const HSTS_SECONDS = 31536000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age='.self::HSTS_SECONDS.'; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
