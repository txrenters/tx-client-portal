<?php

namespace App\Services\Admin;

use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\PortalVisit;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who has not got going on the portal, so staff know whom to follow up with.
 *
 * Every list is read from our own tables, so the page never waits on
 * Propertyware. "Gone quiet" relies on last_seen_at, which only exists from
 * the day it was added: anyone not seen since then counts as never seen.
 */
class PortalInsights
{
    /** Days without a visit before someone counts as gone quiet. */
    public const QUIET_DAYS = 30;

    /** Rows shown in each list. */
    private const LIMIT = 25;

    /** Days the visit and sign-in figures cover. */
    public const ACTIVITY_DAYS = 30;

    /** Failed attempts on one address in a week that are worth a look. */
    public const REPEATED_FAILURES = 3;

    /** Sign-in log rows per page. */
    private const LOG_PER_PAGE = 30;

    /**
     * Portal visits over the last month: how many, by whom, web or app, per day.
     *
     * @return array{total: int, visitors: int, web: int, app: int, daily: array<int, array{date: string, web: int, app: int}>}
     */
    public function visits(): array
    {
        $visits = PortalVisit::query()
            ->where('created_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->get(['user_id', 'channel', 'created_at']);

        $zone = config('app.display_timezone', config('app.timezone'));
        $byDay = $visits->groupBy(fn (PortalVisit $visit): string => $visit->created_at->timezone($zone)->toDateString());

        return [
            'total' => $visits->count(),
            'visitors' => $visits->pluck('user_id')->unique()->count(),
            'web' => $visits->where('channel', 'web')->count(),
            'app' => $visits->where('channel', 'app')->count(),
            'daily' => collect(range(self::ACTIVITY_DAYS - 1, 0))
                ->map(function (int $daysAgo) use ($byDay, $zone): array {
                    $date = now($zone)->subDays($daysAgo)->toDateString();
                    $day = $byDay->get($date, collect());

                    return ['date' => $date, 'web' => $day->where('channel', 'web')->count(), 'app' => $day->where('channel', 'app')->count()];
                })
                ->all(),
        ];
    }

    /**
     * Sign-ins and failed attempts over the last month, and addresses that
     * failed again and again this week: someone locked out, or someone
     * guessing.
     *
     * @return array<string, mixed>
     */
    public function signIns(): array
    {
        $counts = SignInEvent::query()
            ->where('created_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->selectRaw('event, count(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event');

        $repeated = SignInEvent::query()
            ->whereIn('event', ['failed', 'failed_two_factor', 'locked_out'])
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('email')
            ->get(['email', 'user_id', 'ip_address', 'created_at'])
            ->groupBy('email')
            ->filter(fn ($attempts): bool => $attempts->count() >= self::REPEATED_FAILURES)
            ->map(fn ($attempts, string $email): array => [
                'email' => $email,
                'userId' => $attempts->pluck('user_id')->filter()->first(),
                'attempts' => $attempts->count(),
                'addresses' => $attempts->pluck('ip_address')->filter()->unique()->count(),
                'lastAt' => $attempts->max('created_at')->toIso8601String(),
            ])
            ->sortByDesc('attempts')
            ->take(10)
            ->values()
            ->all();

        return [
            'successful' => (int) ($counts['signed_in'] ?? 0),
            'failed' => (int) (($counts['failed'] ?? 0) + ($counts['failed_two_factor'] ?? 0)),
            'lockedOut' => (int) ($counts['locked_out'] ?? 0),
            'blocked' => (int) ($counts['blocked'] ?? 0),
            'repeated' => $repeated,
        ];
    }

    /**
     * The sign-in log, newest first: everyone, only failures, or one address.
     *
     * @return LengthAwarePaginator<int, array{id: int, event: string, email: ?string, user: array{id: int, name: string}|null, channel: string, method: ?string, ipAddress: ?string, device: ?string, at: string}>
     */
    public function signInLog(string $filter = 'all', ?string $email = null): LengthAwarePaginator
    {
        return SignInEvent::query()
            ->with('user:id,name,email')
            ->when($filter === 'failed', fn ($query) => $query->whereIn('event', ['failed', 'failed_two_factor', 'locked_out', 'blocked']))
            ->when($filter === 'signed_in', fn ($query) => $query->where('event', 'signed_in'))
            ->when(filled($email), fn ($query) => $query->where('email', mb_strtolower((string) $email)))
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::LOG_PER_PAGE, pageName: 'log_page')
            ->withQueryString()
            ->through(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'email' => $event->email,
                'user' => $event->user === null ? null : ['id' => $event->user->id, 'name' => $event->user->name],
                'channel' => $event->channel,
                'method' => $event->method,
                'ipAddress' => $event->ip_address,
                'device' => $this->device($event->user_agent),
                'at' => $event->created_at->toIso8601String(),
            ]);
    }

    /**
     * A user agent as a person would describe the device: "Chrome on Mac".
     */
    public function device(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Darwin') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $client = match (true) {
            str_contains($userAgent, 'Expo') || str_contains($userAgent, 'okhttp') || str_contains($userAgent, 'CFNetwork') => 'the app',
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        return match (true) {
            $client !== null && $system !== null => "{$client} on {$system}",
            default => $client ?? $system ?? Str::limit($userAgent, 40),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $clients = User::query()->whereHas('clientAccounts')->whereNull('suspended_at');

        return [
            'quietDays' => self::QUIET_DAYS,
            'adoption' => [
                'clients' => (clone $clients)->count(),
                'onApp' => (clone $clients)->whereHas('tokens')->count(),
                'withPush' => (clone $clients)->whereHas('pushTokens')->count(),
                'activeThisMonth' => (clone $clients)->where('last_seen_at', '>=', now()->subDays(self::QUIET_DAYS))->count(),
            ],
            'unconfirmed' => $this->people(
                User::query()->whereNull('email_verified_at')->whereNull('suspended_at')->where('created_at', '<=', now()->subDay()),
                'created_at',
            ),
            'unlinked' => $this->people(
                User::query()->whereNotNull('email_verified_at')->whereNull('suspended_at')->whereDoesntHave('clientAccounts'),
                'created_at',
            ),
            'quiet' => $this->people(
                User::query()->whereHas('clientAccounts')->whereNull('suspended_at')
                    ->where(fn ($query) => $query->where('last_seen_at', '<', now()->subDays(self::QUIET_DAYS))
                        ->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('created_at', '<', now()->subDays(self::QUIET_DAYS)))),
                'last_seen_at',
                oldestFirst: true,
            ),
            'unaccepted' => ClientInvitation::query()
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->where('expires_at', '<=', now())
                // Someone who signed up another way no longer needs chasing.
                ->whereNotIn(DB::raw('lower(email)'), User::query()->select(DB::raw('lower(email)')))
                ->latest('expires_at')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (ClientInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expiredAt' => $invitation->expires_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return array{total: int, people: array<int, array{id: int, name: string, email: string, joinedAt: ?string, lastSeenAt: ?string, roles: array<int, string>}>}
     */
    private function people($query, string $orderBy, bool $oldestFirst = false): array
    {
        return [
            'total' => (clone $query)->count(),
            'people' => $query
                ->with('clientAccounts:id,user_id,role')
                ->orderBy($orderBy, $oldestFirst ? 'asc' : 'desc')
                ->orderBy('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'joinedAt' => $user->created_at?->toIso8601String(),
                    'lastSeenAt' => $user->last_seen_at?->toIso8601String(),
                    'roles' => $user->clientAccounts->map(fn (ClientAccount $account): string => $account->role->value)->unique()->values()->all(),
                ])
                ->all(),
        ];
    }
}
