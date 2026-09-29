<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsReferrals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreReferralRequest;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ReferralController extends Controller
{
    use ShowsReferrals;

    public function index(Request $request, ReferralProgram $program): Response
    {
        return $this->renderReferrals($request, ClientRole::Owner, $program);
    }

    public function store(StoreReferralRequest $request, ReferralProgram $program): RedirectResponse
    {
        return $this->storeReferral($request, ClientRole::Owner, $program);
    }
}
