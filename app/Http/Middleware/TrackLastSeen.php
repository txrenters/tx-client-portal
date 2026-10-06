<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\PortalVisit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Note when a person last used the portal or the apps, and count a visit
 * when they come back after a break, for the admin insights page.
 *
 * Written at most every quarter hour, so a busy session costs one update
 * rather than one per request, and never while staff are signed in as the
 * person: an admin looking around is not the client coming back.
 */
class TrackLastSeen
{
    /** How stale the stored time may get before it is written again. */
    private const MINUTES = 15;

    /** Time away after which coming back counts as a new visit. */
    private const VISIT_GAP_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        // A login deleted during this very request has nothing left to track.
        if ($user === null || ! $user->exists || ($request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY))) {
            return $response;
        }

        $previous = $user->last_seen_at;

        // Back after a real break: count it as a visit, for the insights page.
        if ($previous === null || $previous->lt(now()->subMinutes(self::VISIT_GAP_MINUTES))) {
            PortalVisit::create(['user_id' => $user->id, 'channel' => $request->is('api/*') ? 'app' : 'web']);
        }

        if ($previous === null || $previous->lt(now()->subMinutes(self::MINUTES))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
