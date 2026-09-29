<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Promotion;
use App\Services\Promotions\PromotionBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The once-a-day promotion popup for the apps.
 */
class PromotionController extends Controller
{
    /**
     * The promotion to pop up now, or null when there is nothing due today.
     */
    public function show(Request $request, ClientAccount $account, PromotionBoard $board): JsonResponse
    {
        return response()->json(['data' => $board->popupFor($request->user(), $account->role)]);
    }

    /**
     * The app showed it, the client tapped it, or turned it off for good.
     */
    public function record(Request $request, ClientAccount $account, Promotion $promotion, string $action, PromotionBoard $board): Response
    {
        $board->record($promotion, $request->user(), $action);

        return response()->noContent();
    }
}
