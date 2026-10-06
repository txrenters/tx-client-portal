<?php

namespace App\Services\Admin;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * The portal's own user records, for staff support.
 *
 * Everything an admin can do here is recoverable: a password reset is a link
 * the person still has to click, revoking app sessions only signs them out,
 * unlinking accounts hands them back on the next sign-in because account
 * discovery re-reads Propertyware, and a suspension is lifted by clearing one
 * column. Nothing deletes a user, because nothing in a support workflow needs
 * to and the damage would not be undoable.
 */
class UserDirectory
{
    /** Users on one page of the list. */
    private const PER_PAGE = 25;

    /**
     * Users, newest first, with the accounts and sessions attached to each,
     * optionally only the suspended or the unconfirmed ones.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, ?string $filter = null): LengthAwarePaginator
    {
        $tokenCounts = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->selectRaw('tokenable_id, count(*) as total, max(last_used_at) as last_used_at')
            ->groupBy('tokenable_id')
            ->get()
            ->keyBy('tokenable_id');

        return User::query()
            ->with('clientAccounts:id,user_id,role,propertyware_id')
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term]);
                });
            })
            ->when($filter === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($filter === 'unverified', fn ($query) => $query->whereNull('email_verified_at'))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => $this->present($user, $tokenCounts->get($user->id)));
    }

    /**
     * One person in full, for their page in the admin area.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user): array
    {
        $user->load('clientAccounts:id,user_id,role,propertyware_id,label,created_at');

        $tokens = $user->tokens()->latest('last_used_at')->get(['id', 'name', 'last_used_at', 'created_at']);

        return [
            ...$this->present($user, (object) [
                'total' => $tokens->count(),
                'last_used_at' => $tokens->max('last_used_at')?->toIso8601String(),
            ]),
            'accounts' => $user->clientAccounts
                ->map(fn (ClientAccount $account): array => [
                    'id' => $account->id,
                    'role' => $account->role->value,
                    'propertywareId' => $account->propertyware_id,
                    'label' => $account->label,
                    'linkedAt' => $account->created_at?->toIso8601String(),
                ])
                ->all(),
            'sessions' => $tokens
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                    'createdAt' => $token->created_at?->toIso8601String(),
                ])
                ->all(),
            'devices' => $user->pushTokens()->count(),
            'openHelpRequests' => $user->helpRequests()->where('status', 'open')->count(),
        ];
    }

    /**
     * Shut the person out of the portal and the apps.
     *
     * Their app tokens and push registrations go straight away, and the
     * middleware turns away their browser session on its next request, so
     * nothing they had open keeps working.
     */
    public function suspend(User $user): void
    {
        $user->forceFill(['suspended_at' => now()])->save();
        $user->tokens()->delete();
        $user->pushTokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /**
     * Let a suspended person sign in again.
     */
    public function restore(User $user): void
    {
        $user->forceFill(['suspended_at' => null])->save();
    }

    /**
     * Treat the email address as confirmed, for someone whose confirmation
     * mail never arrives and who staff have verified another way.
     */
    public function markEmailVerified(User $user): bool
    {
        return $user->hasVerifiedEmail() ? false : $user->markEmailAsVerified();
    }

    /**
     * Send the confirmation email again. Nothing to send once confirmed.
     */
    public function resendVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }

    /**
     * Turn off two-factor for someone who lost the phone their codes are on.
     * They sign in with their password alone and can set it up again.
     */
    public function resetTwoFactor(User $user): bool
    {
        if ($user->two_factor_secret === null && $user->two_factor_confirmed_at === null) {
            return false;
        }

        app(DisableTwoFactorAuthentication::class)($user);

        return true;
    }

    /**
     * Send the password reset email Laravel already uses, so the link, its
     * expiry and the throttling are the same ones a client gets themselves.
     *
     * @return array{sent: bool, reason: string}
     */
    public function sendPasswordReset(User $user): array
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        return [
            'sent' => $status === Password::RESET_LINK_SENT,
            'reason' => (string) __($status),
        ];
    }

    /**
     * Sign the user out of the iOS and Android apps.
     *
     * Their browser session is untouched: this is for a lost or replaced
     * phone, which is the case that actually comes up.
     */
    public function revokeAppSessions(User $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * Forget which Propertyware contacts a user is linked to.
     *
     * Discovery re-reads Propertyware on their next request, so this is the
     * fix for someone whose records were wrong when they first signed up, or
     * who was linked to a contact that has since changed.
     */
    public function relinkAccounts(User $user): int
    {
        return (int) ClientAccount::query()->where('user_id', $user->id)->delete();
    }

    /**
     * One user as the dashboard shows them.
     *
     * @return array<string, mixed>
     */
    private function present(User $user, ?object $tokens): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'verifiedAt' => $user->email_verified_at?->toIso8601String(),
            'twoFactor' => $user->two_factor_confirmed_at !== null,
            'suspendedAt' => $user->suspended_at?->toIso8601String(),
            'isStaff' => $user->isStaff(),
            'isDemo' => $user->is_demo,
            'joinedAt' => $user->created_at?->toIso8601String(),
            'accounts' => $user->clientAccounts
                ->map(fn (ClientAccount $account): array => [
                    'id' => $account->id,
                    'role' => $account->role->value,
                    'propertywareId' => $account->propertyware_id,
                ])
                ->all(),
            'appSessions' => (int) ($tokens->total ?? 0),
            'appLastUsedAt' => $tokens->last_used_at ?? null,
        ];
    }
}
