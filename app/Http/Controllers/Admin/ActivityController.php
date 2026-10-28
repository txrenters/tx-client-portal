<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PortalActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    /**
     * What has happened in the portal lately.
     */
    public function __invoke(Request $request, PortalActivity $activity): Response
    {
        $withApprovalHistory = $request->user()->can('view-approval-history');

        return Inertia::render('admin/Activity', [
            'activity' => Inertia::defer(fn (): array => $activity->recent(100, $withApprovalHistory)),
        ]);
    }
}
