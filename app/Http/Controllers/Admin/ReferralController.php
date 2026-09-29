<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReferralStatus;
use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\Admin\AdminAudit;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    use FlashesToasts;

    /**
     * Every property owner clients have referred, for staff to follow up.
     */
    public function index(Request $request, ReferralProgram $program): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReferralStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = isset($validated['status']) ? ReferralStatus::from($validated['status']) : null;
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/Referrals', [
            'status' => $status?->value,
            'search' => $search,
            'statuses' => collect(ReferralStatus::cases())
                ->map(fn (ReferralStatus $case): array => ['value' => $case->value, 'label' => $case->label()])
                ->all(),
            'reward' => $program->reward(),
            'referrals' => $program->forStaff($status, $search === '' ? null : $search),
        ]);
    }

    /**
     * Move a referral along: contacted, signed, or not a fit.
     */
    public function update(Request $request, Referral $referral, ReferralProgram $program, AdminAudit $audit): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(ReferralStatus::class)]]);
        $status = ReferralStatus::from($validated['status']);

        if ($referral->status !== $status) {
            $program->changeStatus($referral, $status);
            $audit->record($request->user(), 'referral_status_changed', "Marked the referral of {$referral->first_name} {$referral->last_name} as {$status->label()}");
        }

        return back();
    }

    /**
     * Send a referral to the leads webhook again after it failed to get there.
     */
    public function resendLead(Request $request, Referral $referral, ReferralProgram $program, AdminAudit $audit): RedirectResponse
    {
        if (! $program->resendLead($referral)) {
            $this->toast($referral->lead_sent_at !== null
                ? 'That referral is already in the leads.'
                : 'The leads webhook is not set up, so there is nowhere to send it.', 'error');

            return back();
        }

        $audit->record($request->user(), 'referral_lead_resent', "Resent the referral of {$referral->first_name} {$referral->last_name} to the leads");
        $this->toast('Sending it to the leads again.');

        return back();
    }

    /**
     * The reward wording clients see above the referral form. Empty goes back
     * to the default, the wording on the printed card.
     */
    public function updateReward(Request $request, ReferralProgram $program): RedirectResponse
    {
        $validated = $request->validate(['reward' => ['nullable', 'string', 'max:500']]);

        $program->setReward($validated['reward'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Referral reward saved.')]);

        return back();
    }
}
