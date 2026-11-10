<?php

namespace App\PortalSso;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers when the portal signed a person out, so every session that person started
 * through the portal before then ends, whoever the session is signed in as by now (an
 * admin impersonating a client keeps their portal sign-in in the session).
 */
class PortalSignOuts
{
    public static function record(string $sub): void
    {
        // Kept for longer than any portal sign-in (12 hours) or idle session can last.
        $minutes = max((int) config('session.lifetime'), 12 * 60) + 60;

        Cache::put(self::key($sub), now()->getTimestamp(), now()->addMinutes($minutes));
    }

    /** Whether a portal sign-in made at $signedInAt has been signed out since. */
    public static function endedSince(string $sub, int $signedInAt): bool
    {
        $signedOutAt = Cache::get(self::key($sub));

        return is_int($signedOutAt) && $signedOutAt >= $signedInAt;
    }

    private static function key(string $sub): string
    {
        return 'portal-sso:'.config('portal.app_slug').':signed-out:'.hash('sha256', $sub);
    }
}
