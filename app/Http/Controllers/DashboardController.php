<?php

namespace App\Http\Controllers;

use App\Enums\ClientRole;
use App\Http\Middleware\EnsureClientRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Send everyone to the dashboard that is theirs.
     *
     * Staff land on the admin dashboard, which is the one that answers their
     * questions. An admin who also holds client accounts keeps them: the
     * portals stay a click away in the sidebar, this only decides where
     * "dashboard" goes.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (Gate::allows('admin')) {
            return to_route('admin.dashboard');
        }

        $role = $request->user()->preferredClientRole(
            ClientRole::tryFrom((string) $request->session()->get(EnsureClientRole::SESSION_KEY)),
        );

        if ($role === null) {
            return Inertia::render('auth/AccountPending');
        }

        return to_route($role->dashboardRoute());
    }
}
