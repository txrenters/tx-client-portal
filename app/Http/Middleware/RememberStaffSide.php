<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DashboardController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that a member of staff is on the staff side, so "Dashboard" brings
 * them back here until they pick one of their own client accounts. Sits
 * inside the admin gate, so only staff ever get here.
 */
class RememberStaffSide
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->session()->put(DashboardController::SIDE_SESSION_KEY, DashboardController::STAFF_SIDE);

        return $next($request);
    }
}
