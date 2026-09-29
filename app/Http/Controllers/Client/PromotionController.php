<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\Promotions\PromotionBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The once-a-day promotion popup for the web portal. The apps use the API's
 * PromotionController; both go through PromotionBoard.
 */
class PromotionController extends Controller
{
    use ResolvesClientScope;

    /**
     * The promotion to pop up now, or null. Staff signed in as a client never
     * see it, so checking someone's account does not use up their day.
     */
    public function show(Request $request, PromotionBoard $board): JsonResponse
    {
        $account = $this->currentClientAccount($request);

        if ($account === null || $request->session()->has(ImpersonationController::SESSION_KEY)) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $board->popupFor($request->user(), $account->role)]);
    }

    public function record(Request $request, Promotion $promotion, string $action, PromotionBoard $board): Response
    {
        if (! $request->session()->has(ImpersonationController::SESSION_KEY)) {
            $board->record($promotion, $request->user(), $action);
        }

        return response()->noContent();
    }
}
