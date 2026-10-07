<?php

namespace App\Http\Controllers;

use App\Services\Admin\LandingPageTracker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LandingPageEventController extends Controller
{
    /**
     * Something a visitor did on the landing page, like playing the tutorial.
     */
    public function __invoke(Request $request, LandingPageTracker $tracker): Response
    {
        $validated = $request->validate([
            'event' => ['required', Rule::in(LandingPageTracker::PAGE_EVENTS)],
        ]);

        if ($request->user() === null) {
            $tracker->record($request, $validated['event']);
        }

        return response()->noContent();
    }
}
