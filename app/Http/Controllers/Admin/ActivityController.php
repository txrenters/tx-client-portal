<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PortalActivity;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    /**
     * What has happened in the portal lately.
     */
    public function __invoke(PortalActivity $activity): Response
    {
        return Inertia::render('admin/Activity', [
            'activity' => Inertia::defer(fn (): array => $activity->recent(100)),
        ]);
    }
}
