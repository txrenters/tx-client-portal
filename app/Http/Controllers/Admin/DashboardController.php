<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\IntegrationHealth;
use App\Services\Admin\PortalActivity;
use App\Services\Admin\PortalStatistics;
use App\Services\Help\HelpDesk;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * How the portal is being used, and whether the services it leans on are up.
     *
     * The statistics come from our own tables and land with the page. The
     * integration checks call four services, so they are deferred: a vendor
     * being slow should not hold up the numbers.
     */
    public function __invoke(Request $request, PortalStatistics $statistics, IntegrationHealth $health, PortalActivity $activity, HelpDesk $helpDesk): Response
    {
        $withApprovalHistory = $request->user()->can('view-approval-history');

        return Inertia::render('admin/Dashboard', [
            'stats' => $statistics->summary(),
            'queue' => $health->queue(),
            'openHelpRequests' => $helpDesk->openCount(),
            'integrations' => Inertia::defer(fn (): array => $health->all()),
            'activity' => Inertia::defer(fn (): array => $activity->recent(12, $withApprovalHistory)),
        ]);
    }
}
