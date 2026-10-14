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
    /** Which side a member of staff who also holds client accounts last chose. */
    public const SIDE_SESSION_KEY = 'portal_side';

    public const STAFF_SIDE = 'staff';

    public const CLIENT_SIDE = 'client';

    /**
     * Send everyone to the dashboard that is theirs.
     *
     * Staff land on the admin dashboard, which is the one that answers their
     * questions. Staff who also hold client accounts go wherever they last
     * were: picking one of their accounts keeps them on the client side until
     * they open the admin area again.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $onClientSide = $request->session()->get(self::SIDE_SESSION_KEY) === self::CLIENT_SIDE
            && $request->user()->clientRoles()->isNotEmpty();

        if (Gate::allows('admin') && ! $onClientSide) {
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
