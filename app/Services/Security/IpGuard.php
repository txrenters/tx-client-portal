<?php

namespace App\Services\Security;

use App\Models\BlockedIp;
use App\Models\SecurityEvent;
use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Spots addresses attacking the portal and turns them away.
 *
 * An address is blocked automatically for a day when, within an hour, it
 * fails sign-ins against many different emails (credential stuffing), fails
 * very many sign-ins at all (guessing one password), or asks for the files
 * scanners look for. Staff can block and unblock by hand on the Security page.
 *
 * Two things are never blocked automatically, because blocking them would
 * shut out real people: private and reserved addresses (a proxy, or a
 * misconfigured TRUSTED_PROXIES making everyone look like one), and any
 * address a member of staff has signed in from in the last 30 days.
 */
class IpGuard
{
    /** Failures from one address in an hour, against at least STUFFING_EMAILS emails. */
    public const STUFFING_FAILURES = 20;

    public const STUFFING_EMAILS = 5;

    /** Failures from one address in an hour, whatever the emails. */
    public const BRUTE_FORCE_FAILURES = 30;

    /** Scanner paths from one address in an hour. */
    public const PROBES = 3;

    /** How long an automatic block lasts. */
    public const AUTO_BLOCK_HOURS = 24;

    /** Failed sign-ins across the whole portal in an hour that are worth an alert. */
    public const SPIKE_FAILURES = 100;

    /**
     * Paths only a scanner asks for: nothing in the portal lives there.
     *
     * @var array<int, string>
     */
    public const PROBE_PATTERNS = [
        'wp-admin*', 'wp-login*', 'wp-content*', 'wp-includes*', 'xmlrpc.php',
        '.env*', '*/.env*', '.git*', '*/.git/*', '.aws*', '.ssh*', '.htaccess', '.ds_store',
        'phpmyadmin*', 'pma*', 'adminer*', 'cgi-bin*', 'vendor/phpunit*', 'server-status',
        '*.sql', '*.bak', 'config.php*', 'wp-config*',
    ];

    private const CACHE_KEY = 'security:blocked-ips';

    private const FAILURE_EVENTS = ['failed', 'failed_two_factor', 'locked_out'];

    /**
     * Whether requests from this address are refused.
     */
    public function isBlocked(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        $blocked = Cache::remember(self::CACHE_KEY, 60, fn (): array => BlockedIp::query()
            ->active()
            ->get(['ip_address', 'expires_at'])
            ->mapWithKeys(fn (BlockedIp $block): array => [$block->ip_address => $block->expires_at?->getTimestamp()])
            ->all());

        if (! array_key_exists($ip, $blocked)) {
            return false;
        }

        return $blocked[$ip] === null || $blocked[$ip] > now()->getTimestamp();
    }

    /**
     * Whether a path is one only a scanner would ask for.
     */
    public function isProbe(Request $request): bool
    {
        return Str::is(self::PROBE_PATTERNS, ltrim(mb_strtolower($request->path()), '/'));
    }

    /**
     * Note a security event, then see whether the address has earned a block.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(Request $request, string $type, array $details = [], ?User $user = null): SecurityEvent
    {
        $event = SecurityEvent::create([
            'type' => $type,
            'ip_address' => $request->ip(),
            'user_id' => $user->id ?? $request->user()?->id,
            'path' => Str::limit('/'.ltrim($request->path(), '/'), 500, ''),
            'user_agent' => $request->userAgent() === null ? null : Str::limit($request->userAgent(), 500, ''),
            'details' => $details === [] ? null : $details,
        ]);

        if ($type === 'probe') {
            $this->assessProbes($request->ip());
        }

        return $event;
    }

    /**
     * Count a refused request against its block, and note it now and then:
     * once per address every ten minutes, so a flood does not flood the log.
     */
    public function refused(Request $request): void
    {
        $ip = (string) $request->ip();

        if (Cache::add("security:refused:{$ip}", true, now()->addMinutes(10))) {
            $this->record($request, 'blocked_request');
        }

        BlockedIp::query()->where('ip_address', $ip)->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]);
    }

    /**
     * After a failed sign-in: block the address if it is attacking, and warn
     * staff if failures are spiking across the whole portal.
     */
    public function assessSignInFailure(?string $ip): void
    {
        if ($ip !== null) {
            $recent = SignInEvent::query()
                ->where('ip_address', $ip)
                ->whereIn('event', self::FAILURE_EVENTS)
                ->where('created_at', '>=', now()->subHour())
                ->get(['email']);

            $emails = $recent->pluck('email')->filter()->unique()->count();

            if ($recent->count() >= self::STUFFING_FAILURES && $emails >= self::STUFFING_EMAILS) {
                $this->autoBlock($ip, "{$recent->count()} failed sign-ins across {$emails} different emails in an hour");
            } elseif ($recent->count() >= self::BRUTE_FORCE_FAILURES) {
                $this->autoBlock($ip, "{$recent->count()} failed sign-ins in an hour");
            }
        }

        $portalWide = SignInEvent::query()
            ->whereIn('event', self::FAILURE_EVENTS)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($portalWide >= self::SPIKE_FAILURES && Cache::add('security:spike-alert', true, now()->addHour())) {
            $this->alert(
                'Failed sign-ins are spiking',
                "{$portalWide} failed sign-ins across the portal in the last hour. It may be an attack spread over many addresses.",
            );
        }
    }

    /**
     * Block an address by hand.
     */
    public function block(string $ip, string $reason, ?CarbonInterface $expiresAt, User $by): BlockedIp
    {
        $block = BlockedIp::query()->updateOrCreate(
            ['ip_address' => $ip],
            ['reason' => $reason, 'source' => 'manual', 'expires_at' => $expiresAt, 'blocked_by' => $by->id],
        );

        Cache::forget(self::CACHE_KEY);

        return $block;
    }

    public function unblock(BlockedIp $block): void
    {
        $block->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Whether an address may be blocked automatically; see the class notes.
     */
    public function mayAutoBlock(string $ip): bool
    {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        return $public && ! $this->isStaffAddress($ip);
    }

    /**
     * Whether staff have signed in from this address lately.
     */
    public function isStaffAddress(string $ip): bool
    {
        return SignInEvent::query()
            ->where('ip_address', $ip)
            ->where('event', 'signed_in')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->contains(fn (SignInEvent $event): bool => $event->user?->isStaff() === true);
    }

    /**
     * Addresses with repeated failures or any probing over the window, worst first.
     *
     * @return array<int, array{ip: string, failures: int, emails: int, sampleEmails: array<int, string>, probes: int, lastAt: string, blocked: bool}>
     */
    public function suspicious(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $failures = SignInEvent::query()
            ->whereIn('event', self::FAILURE_EVENTS)
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->get(['ip_address', 'email', 'created_at'])
            ->groupBy('ip_address');

        $probes = SecurityEvent::query()
            ->whereIn('type', ['probe', 'admin_denied', 'invalid_invitation', 'bad_webhook_signature', 'unknown_registration'])
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->get(['ip_address', 'created_at'])
            ->groupBy('ip_address');

        return collect($failures->keys())->merge($probes->keys())->unique()
            ->map(function (string $ip) use ($failures, $probes): array {
                $failed = $failures->get($ip, collect());
                $probed = $probes->get($ip, collect());
                $emails = $failed->pluck('email')->filter()->unique()->values();

                return [
                    'ip' => $ip,
                    'failures' => $failed->count(),
                    'emails' => $emails->count(),
                    'sampleEmails' => $emails->take(5)->all(),
                    'probes' => $probed->count(),
                    'lastAt' => $failed->pluck('created_at')->merge($probed->pluck('created_at'))->max()->toIso8601String(),
                    'blocked' => $this->isBlocked($ip),
                ];
            })
            // One or two typos are not suspicious; a pattern is.
            ->filter(fn (array $row): bool => $row['failures'] >= 3 || $row['probes'] >= 1)
            ->sortByDesc(fn (array $row): int => $row['failures'] + ($row['probes'] * 5))
            ->values()
            ->take(50)
            ->all();
    }

    private function assessProbes(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $probes = SecurityEvent::query()
            ->where('ip_address', $ip)
            ->where('type', 'probe')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($probes >= self::PROBES) {
            $this->autoBlock($ip, "{$probes} requests for scanner paths in an hour");
        }
    }

    private function autoBlock(string $ip, string $reason): void
    {
        if ($this->isBlocked($ip) || ! $this->mayAutoBlock($ip)) {
            return;
        }

        BlockedIp::query()->updateOrCreate(
            ['ip_address' => $ip],
            ['reason' => $reason, 'source' => 'automatic', 'expires_at' => now()->addHours(self::AUTO_BLOCK_HOURS), 'blocked_by' => null],
        );
        Cache::forget(self::CACHE_KEY);

        SecurityEvent::create(['type' => 'auto_blocked', 'ip_address' => $ip, 'details' => ['reason' => $reason]]);

        $this->alert(
            "Blocked {$ip} for ".self::AUTO_BLOCK_HOURS.' hours',
            "The portal blocked {$ip} automatically: {$reason}. Unblock it on the Security page if it was a real person.",
        );
    }

    /**
     * Email the owners and admins.
     */
    private function alert(string $subject, string $message): void
    {
        $emails = collect(config()->array('services.admin_emails'))
            ->merge(User::query()->where('staff_role', 'admin')->whereNull('suspended_at')->pluck('email'))
            ->map(fn (string $email): string => mb_strtolower($email))
            ->unique()
            ->values()
            ->all();

        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new SecurityAlertNotification($subject, $message));
        }
    }
}
