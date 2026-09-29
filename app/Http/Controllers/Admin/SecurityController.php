<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\SecurityEvent;
use App\Services\Admin\AdminAudit;
use App\Services\Security\IpGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Addresses attacking the portal, the ones it refuses, and the log of
 * everything suspicious it has seen.
 */
class SecurityController extends Controller
{
    use FlashesToasts;

    /** Event types staff can filter the log by, with their labels. */
    public const EVENT_TYPES = [
        'probe' => 'Scanner probe',
        'admin_denied' => 'Client tried an admin page',
        'invalid_invitation' => 'Invalid invitation link',
        'unknown_registration' => 'Sign-up with an unknown email',
        'bad_webhook_signature' => 'Bad webhook signature',
        'blocked_request' => 'Blocked address came back',
        'auto_blocked' => 'Blocked automatically',
    ];

    public function __construct(private IpGuard $guard, private AdminAudit $audit) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'window' => ['nullable', 'in:24,168'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::EVENT_TYPES))],
            'ip' => ['nullable', 'ip'],
        ]);
        $window = (int) ($validated['window'] ?? 24);

        return Inertia::render('admin/Security', [
            'window' => $window,
            'type' => $validated['type'] ?? null,
            'ip' => $validated['ip'] ?? null,
            'yourIp' => $request->ip(),
            'eventTypes' => self::EVENT_TYPES,
            'thresholds' => [
                'stuffingFailures' => IpGuard::STUFFING_FAILURES,
                'stuffingEmails' => IpGuard::STUFFING_EMAILS,
                'bruteForceFailures' => IpGuard::BRUTE_FORCE_FAILURES,
                'probes' => IpGuard::PROBES,
                'autoBlockHours' => IpGuard::AUTO_BLOCK_HOURS,
            ],
            'suspicious' => $this->guard->suspicious($window),
            'blocks' => BlockedIp::query()
                ->with('blocker:id,email')
                // Current blocks, and ones that ran out in the last month.
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->subDays(30)))
                ->latest()
                ->get()
                ->map(fn (BlockedIp $block): array => [
                    'id' => $block->id,
                    'ip' => $block->ip_address,
                    'reason' => $block->reason,
                    'source' => $block->source,
                    'active' => $block->isActive(),
                    'expiresAt' => $block->expires_at?->toIso8601String(),
                    'blockedBy' => $block->blocker?->email,
                    'hits' => $block->hits,
                    'lastHitAt' => $block->last_hit_at?->toIso8601String(),
                    'createdAt' => $block->created_at->toIso8601String(),
                ])
                ->all(),
            'events' => SecurityEvent::query()
                ->with('user:id,name,email')
                ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
                ->when($validated['ip'] ?? null, fn ($query, string $ip) => $query->where('ip_address', $ip))
                ->latest('created_at')
                ->latest('id')
                ->paginate(30, pageName: 'events_page')
                ->withQueryString()
                ->through(fn (SecurityEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'label' => self::EVENT_TYPES[$event->type] ?? $event->type,
                    'ip' => $event->ip_address,
                    'path' => $event->path,
                    'user' => $event->user === null ? null : ['id' => $event->user->id, 'email' => $event->user->email],
                    'reason' => $event->details['reason'] ?? null,
                    'at' => $event->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
            'reason' => ['required', 'string', 'max:500'],
            // Hours, or empty for until someone lifts it.
            'hours' => ['nullable', 'integer', 'in:1,24,168,720'],
        ]);

        if ($validated['ip'] === $request->ip()) {
            throw ValidationException::withMessages(['ip' => __('That is the address you are using now. Blocking it would lock you out.')]);
        }

        $expiresAt = isset($validated['hours']) ? now()->addHours((int) $validated['hours']) : null;
        $block = $this->guard->block($validated['ip'], $validated['reason'], $expiresAt, $request->user());

        $this->audit->record($request->user(), 'ip_blocked', "Blocked {$block->ip_address}", details: ['reason' => $block->reason]);
        $this->toast("{$block->ip_address} is blocked.");

        return back();
    }

    public function destroy(Request $request, BlockedIp $block): RedirectResponse
    {
        $this->guard->unblock($block);

        $this->audit->record($request->user(), 'ip_unblocked', "Unblocked {$block->ip_address}");
        $this->toast("{$block->ip_address} is no longer blocked.");

        return back();
    }
}
