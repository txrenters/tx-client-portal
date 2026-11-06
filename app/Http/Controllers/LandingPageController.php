<?php

namespace App\Http\Controllers;

use App\Services\Admin\LandingPageTracker;
use App\Services\Security\ClientWebPause;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /**
     * The public landing page, counted as a visit for Insights unless the
     * viewer is already signed in or the browser is only prefetching it. While
     * client sign-in is paused it stops pointing visitors at the website.
     */
    public function __invoke(Request $request, LandingPageTracker $tracker, ClientWebPause $clientWebPause): Response
    {
        if ($request->user() === null && $request->header('Purpose') !== 'prefetch') {
            $tracker->record($request, LandingPageTracker::VIEW);
        }

        return Inertia::render('Welcome', [
            'isWebPortalOpen' => ! $clientWebPause->isPaused(),
        ]);
    }
}
