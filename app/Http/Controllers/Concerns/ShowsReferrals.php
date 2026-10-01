<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Requests\Client\StoreReferralRequest;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Refer a friend page, the same for owners and residents. The API's
 * ReferralController serves the apps from the same ReferralProgram.
 */
trait ShowsReferrals
{
    use ResolvesClientScope;

    protected function renderReferrals(Request $request, ClientRole $role, ReferralProgram $program): Response
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        return Inertia::render('client/Referrals', [
            'portal' => $role->value,
            'reward' => $program->reward(),
            'referrals' => Inertia::scroll(fn () => $program->forAccount($account, max(1, $request->integer('page', 1)))),
        ]);
    }

    protected function storeReferral(StoreReferralRequest $request, ClientRole $role, ReferralProgram $program): RedirectResponse
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        $program->submit($request->user(), $account, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thank you! We will reach out to them soon.')]);

        return back();
    }
}
