<?php

namespace App\Services\Admin;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How the portal itself is being used, read from our own tables only.
 *
 * Nothing here calls Propertyware: these are questions about the portal — who
 * has signed up, who confirmed their email, who is on the apps — which our own
 * database answers immediately, so the dashboard never waits on a vendor.
 */
class PortalStatistics
{
    /** How many days of sign-ups the chart covers. */
    private const CHART_DAYS = 30;

    /**
     * The headline figures for the dashboard.
     *
     * @return array{
     *     users: array{total: int, verified: int, unverified: int, withTwoFactor: int, joinedThisMonth: int},
     *     accounts: array{total: int, owners: int, residents: int, linkedUsers: int},
     *     invitations: array{pending: int, accepted: int},
     *     sessions: array{mobileTokens: int, activeThisWeek: int},
     *     signups: array<int, array{date: string, count: int}>
     * }
     */
    public function summary(): array
    {
        $now = CarbonImmutable::now();

        return [
            'users' => [
                'total' => User::query()->count(),
                'verified' => User::query()->whereNotNull('email_verified_at')->count(),
                'unverified' => User::query()->whereNull('email_verified_at')->count(),
                'withTwoFactor' => User::query()->whereNotNull('two_factor_confirmed_at')->count(),
                'joinedThisMonth' => User::query()->where('created_at', '>=', $now->startOfMonth())->count(),
            ],
            'accounts' => [
                'total' => ClientAccount::query()->count(),
                'owners' => ClientAccount::query()->where('role', ClientRole::Owner->value)->count(),
                'residents' => ClientAccount::query()->where('role', ClientRole::Resident->value)->count(),
                'linkedUsers' => ClientAccount::query()->distinct()->count('user_id'),
            ],
            'invitations' => [
                'pending' => ClientInvitation::query()->whereNull('accepted_at')->count(),
                'accepted' => ClientInvitation::query()->whereNotNull('accepted_at')->count(),
            ],
            'sessions' => [
                'mobileTokens' => DB::table('personal_access_tokens')->count(),
                'activeThisWeek' => DB::table('personal_access_tokens')
                    ->where('last_used_at', '>=', $now->subWeek())
                    ->count(),
            ],
            'signups' => $this->signups($now),
        ];
    }

    /**
     * Sign-ups per day, with the quiet days filled in so the chart has no gaps.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function signups(CarbonImmutable $now): array
    {
        $start = $now->subDays(self::CHART_DAYS - 1)->startOfDay();

        $counts = User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn (User $user): string => $user->created_at->toDateString())
            ->map(fn ($group): int => $group->count());

        $days = [];

        for ($day = 0; $day < self::CHART_DAYS; $day++) {
            $date = $start->addDays($day)->toDateString();
            $days[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $days;
    }
}
