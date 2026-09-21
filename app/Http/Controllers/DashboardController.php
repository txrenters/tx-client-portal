<?php

namespace App\Http\Controllers;

use App\Enums\ClientRole;
use App\Http\Middleware\EnsureClientRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Send the client to the portal they last used, or explain that their account isn't linked yet.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $role = $request->user()->preferredClientRole(
            ClientRole::tryFrom((string) $request->session()->get(EnsureClientRole::SESSION_KEY)),
        );

        if ($role === null) {
            return Inertia::render('auth/AccountPending');
        }

        return to_route($role->dashboardRoute());
    }
}
