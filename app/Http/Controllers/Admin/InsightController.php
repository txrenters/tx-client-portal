<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PortalInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InsightController extends Controller
{
    /**
     * How the portal is used — visits, sign-ins and failed attempts — and
     * who has not got going on it.
     */
    public function __invoke(Request $request, PortalInsights $insights): Response
    {
        $validated = $request->validate([
            'log' => ['nullable', 'in:all,signed_in,failed'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);
        $filter = $validated['log'] ?? 'all';
        $email = trim((string) ($validated['email'] ?? ''));

        return Inertia::render('admin/Insights', [
            ...$insights->summary(),
            'activityDays' => PortalInsights::ACTIVITY_DAYS,
            'visits' => $insights->visits(),
            'landing' => $insights->landing(),
            'signIns' => $insights->signIns(),
            'logFilter' => $filter,
            'logEmail' => $email,
            'signInLog' => $insights->signInLog($filter, $email === '' ? null : $email),
        ]);
    }
}
