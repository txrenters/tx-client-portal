<?php

namespace App\Services\Admin;

use App\Models\LandingPageEvent;
use Illuminate\Http\Request;

/**
 * Counts what anonymous visitors do on the landing page, for the Insights
 * page, without cookies or an outside service.
 *
 * A visitor is a keyed hash of their address, browser and the day, so the
 * same person counts once a day and can't be recognised the next day or from
 * the stored value. Sign-ups and sign-ins only count for a visitor who saw the
 * landing page that same day.
 */
class LandingPageTracker
{
    public const VIEW = 'view';

    public const TUTORIAL_PLAY = 'tutorial_play';

    public const SIGN_UP = 'sign_up';

    public const SIGN_IN = 'sign_in';

    /** Events the landing page itself may report. */
    public const PAGE_EVENTS = [self::TUTORIAL_PLAY];

    /** Crawlers, link previews and uptime checks, which are not visitors. */
    private const BOTS = '/bot|crawl|spider|slurp|preview|monitor|uptime|curl|wget|python|headless|lighthouse|facebookexternalhit|whatsapp/i';

    public function record(Request $request, string $event): void
    {
        if ($this->isBot($request)) {
            return;
        }

        $day = $this->today();
        $visitor = $this->visitor($request, $day);

        // An outcome only counts when this visitor came through the landing page today.
        if ($event !== self::VIEW && ! $this->viewed($visitor, $day)) {
            return;
        }

        LandingPageEvent::query()->insertOrIgnore([
            'day' => $day,
            'event' => $event,
            'visitor' => $visitor,
            'created_at' => now(),
        ]);
    }

    private function viewed(string $visitor, string $day): bool
    {
        return LandingPageEvent::query()
            ->where('day', $day)
            ->where('event', self::VIEW)
            ->where('visitor', $visitor)
            ->exists();
    }

    private function visitor(Request $request, string $day): string
    {
        return hash_hmac('sha256', $day.'|'.$request->ip().'|'.$request->userAgent(), (string) config('app.key'));
    }

    private function isBot(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        return $agent === '' || preg_match(self::BOTS, $agent) === 1;
    }

    private function today(): string
    {
        return now(config('app.display_timezone', config('app.timezone')))->toDateString();
    }
}
