<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreReferralRequest;
use App\Models\ClientAccount;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Property owners the client has referred, and the reward for doing so.
 */
class ReferralController extends Controller
{
    /**
     * The reward wording and the account's referrals, newest first.
     */
    public function index(Request $request, ClientAccount $account, ReferralProgram $program): JsonResponse
    {
        $referrals = $program->forAccount($account, max(1, $request->integer('page', 1)));

        return response()->json([
            'data' => $referrals->items(),
            'meta' => [
                'reward' => $program->reward(),
                'currentPage' => $referrals->currentPage(),
                'lastPage' => $referrals->lastPage(),
                'total' => $referrals->total(),
            ],
        ]);
    }

    public function store(StoreReferralRequest $request, ClientAccount $account, ReferralProgram $program): JsonResponse
    {
        $referral = $program->submit($request->user(), $account, $request->details());

        return response()->json(['data' => $program->present($referral)], 201);
    }
}
