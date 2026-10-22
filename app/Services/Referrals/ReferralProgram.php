<?php

namespace App\Services\Referrals;

use App\Enums\ReferralStatus;
use App\Jobs\SendReferralToLeads;
use App\Models\ClientAccount;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Clients referring property owners to TexasRenters.com: what they are
 * offered for it, what they sent, and where staff are with each one.
 *
 * Web, API and admin all go through here, so a referral looks the same
 * wherever it was sent or read.
 */
class ReferralProgram
{
    /** The Setting key staff edit the reward text under. */
    public const REWARD_SETTING = 'referral_reward';

    /**
     * The wording until staff change it. Texas law caps what a referrer can be
     * given at a $50 gift card; the owner they send us gets the free months.
     */
    public const DEFAULT_REWARD = "Refer a property owner and get a \$50 gift card! They'll get 2 months of management fees free.";

    public const PER_PAGE = 20;

    /**
     * The reward wording shown above the form.
     */
    public function reward(): string
    {
        return Setting::valueOf(self::REWARD_SETTING) ?: self::DEFAULT_REWARD;
    }

    public function setReward(?string $text): void
    {
        Setting::put(self::REWARD_SETTING, filled($text) ? trim($text) : null);
    }

    /**
     * Record an owner the client referred and send them on to the website's
     * leads pipeline. The owner heard about us through the client, so the
     * source is always a referral, with the client as who referred them.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string, property_address: string, property_details?: ?string}  $details
     */
    public function submit(User $referrer, ClientAccount $account, array $details): Referral
    {
        $referral = Referral::create([
            'user_id' => $referrer->id,
            'client_account_id' => $account->id,
            'referrer_name' => $referrer->name,
            'referrer_role' => $account->role->value,
            'first_name' => trim($details['first_name']),
            'last_name' => trim($details['last_name']),
            'email' => mb_strtolower(trim($details['email'])),
            'phone' => trim($details['phone']),
            'property_address' => trim($details['property_address']),
            'property_details' => filled($details['property_details'] ?? null) ? trim($details['property_details']) : null,
            'source' => SendReferralToLeads::SOURCE,
            'status' => ReferralStatus::New,
        ]);

        SendReferralToLeads::dispatch($referral->id)->afterCommit();

        return $referral;
    }

    /**
     * The referrals sent from this account, newest first.
     *
     * @return LengthAwarePaginator<int, array{id: int, name: string, propertyAddress: string, status: string, statusLabel: string, sentAt: string}>
     */
    public function forAccount(ClientAccount $account, int $page = 1): LengthAwarePaginator
    {
        return Referral::query()
            ->whereBelongsTo($account)
            ->latest()
            ->paginate(self::PER_PAGE, page: $page)
            ->through(fn (Referral $referral): array => $this->present($referral));
    }

    /**
     * What the referrer sees of a referral: who, where and how it is going.
     * Staff-only details stay in the admin area.
     *
     * @return array{id: int, name: string, propertyAddress: string, status: string, statusLabel: string, sentAt: string}
     */
    public function present(Referral $referral): array
    {
        return [
            'id' => $referral->id,
            'name' => trim("{$referral->first_name} {$referral->last_name}"),
            'propertyAddress' => $referral->property_address,
            'status' => $referral->status->value,
            'statusLabel' => $referral->status->label(),
            'sentAt' => $referral->created_at->toIso8601String(),
        ];
    }

    /**
     * Every referral, for staff, optionally narrowed to one status or a search.
     *
     * @return LengthAwarePaginator<int, array{id: int, name: string, propertyAddress: string, status: string, statusLabel: string, sentAt: string, firstName: string, lastName: string, email: string, phone: string, propertyDetails: ?string, source: string, referrerName: string, referrerRole: string, statusChangedAt: ?string, leadSentAt: ?string, leadError: ?string}>
     */
    public function forStaff(?ReferralStatus $status = null, ?string $search = null): LengthAwarePaginator
    {
        return Referral::query()
            // The App Review logins' referrals are never followed up by staff.
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';

                $query->where(function ($query) use ($term): void {
                    foreach (['first_name', 'last_name', 'email', 'phone', 'property_address', 'referrer_name'] as $column) {
                        $query->orWhereRaw("lower({$column}) like ?", [$term]);
                    }
                });
            })
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Referral $referral): array => [
                ...$this->present($referral),
                'firstName' => $referral->first_name,
                'lastName' => $referral->last_name,
                'email' => $referral->email,
                'phone' => $referral->phone,
                'propertyDetails' => $referral->property_details,
                'source' => $referral->source,
                'referrerName' => $referral->referrer_name,
                'referrerRole' => $referral->referrer_role,
                'statusChangedAt' => $referral->status_changed_at?->toIso8601String(),
                'leadSentAt' => $referral->lead_sent_at?->toIso8601String(),
                'leadError' => $referral->lead_error,
            ]);
    }

    /**
     * Try the leads webhook again for a referral that never got through,
     * once whatever was wrong with it is fixed. False when there is nothing
     * to send: it is already in the leads, or no webhook is set up.
     */
    public function resendLead(Referral $referral): bool
    {
        if ($referral->lead_sent_at !== null || blank(config('services.leads.webhook_url'))) {
            return false;
        }

        $referral->update(['lead_error' => null]);
        SendReferralToLeads::dispatch($referral->id);

        return true;
    }

    public function changeStatus(Referral $referral, ReferralStatus $status): void
    {
        if ($referral->status === $status) {
            return;
        }

        $referral->update(['status' => $status, 'status_changed_at' => now()]);
    }
}
