<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromotionRequest;
use App\Models\Promotion;
use App\Services\Admin\AdminAudit;
use App\Services\Promotions\PromotionBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The once-a-day popups clients see on the web portal and in the apps.
 */
class PromotionController extends Controller
{
    public function __construct(private AdminAudit $audit) {}

    public function index(PromotionBoard $board): Response
    {
        return Inertia::render('admin/Promotions', [
            'promotions' => $board->forStaff(),
        ]);
    }

    public function store(PromotionRequest $request): RedirectResponse
    {
        $promotion = Promotion::create($request->promotion());
        $this->audit->record($request->user(), 'promotion_created', "Created the promotion \"{$promotion->title}\"");
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion created.')]);

        return back();
    }

    public function update(PromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        $promotion->update($request->promotion());
        $this->audit->record($request->user(), 'promotion_updated', "Changed the promotion \"{$promotion->title}\"");
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion saved.')]);

        return back();
    }

    public function destroy(Request $request, Promotion $promotion): RedirectResponse
    {
        $promotion->delete();
        $this->audit->record($request->user(), 'promotion_deleted', "Deleted the promotion \"{$promotion->title}\"");
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion deleted.')]);

        return back();
    }
}
