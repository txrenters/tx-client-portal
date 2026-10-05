<?php

namespace App\Http\Controllers\Client;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NeedsAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show what needs the client's attention: unread replies from TexasRenters.com
     * and, for owners, work orders awaiting approval.
     */
    public function index(Request $request, NeedsAttention $attention): Response
    {
        $account = $this->currentClientAccount($request);
        $owner = $account?->role === ClientRole::Owner ? $account : null;

        return Inertia::render('client/Notifications', [
            'isOwner' => $owner !== null,
            'portal' => $account?->role->value,
            'approvals' => Inertia::defer(fn (): ?array => $attention->approvals($owner, 50)),
            'messages' => Inertia::defer(fn (): ?array => $attention->messages($request->user(), $account, 25)),
            'notices' => $account === null ? [] : $attention->notices($request->user(), $account)->latest()->limit(50)->get()
                ->map(fn (DatabaseNotification $notice): array => NeedsAttention::present($notice))->values(),
        ]);
    }

    /**
     * What the header bell's dropdown shows: how many items need attention and the first few.
     * Each section is null when its source couldn't be read.
     */
    public function latest(Request $request, NeedsAttention $attention): JsonResponse
    {
        $account = $this->currentClientAccount($request);
        $approvalSummary = $attention->approvals($account?->role === ClientRole::Owner ? $account : null, 5);
        $messages = $attention->messages($request->user(), $account, 5);
        $notices = $account === null ? collect() : $attention->notices($request->user(), $account)->whereNull('read_at')->latest()->get();

        return response()->json([
            'count' => ($approvalSummary['count'] ?? 0) + ($messages['count'] ?? 0) + $notices->count(),
            'portal' => $account?->role->value,
            // Listen here for new replies; the bell refreshes when one arrives.
            'channel' => $account === null ? null : "contacts.{$account->propertyware_id}.messages",
            'approvals' => $approvalSummary,
            'messages' => $messages,
            'notices' => [
                'count' => $notices->count(),
                'items' => $notices->take(5)->map(fn (DatabaseNotification $notice): array => NeedsAttention::present($notice))->values(),
            ],
        ]);
    }

    /**
     * Open a stored notice: mark it read and go to what it is about.
     */
    public function read(Request $request, string $notification, NeedsAttention $attention): RedirectResponse
    {
        $account = $this->currentClientAccount($request);
        abort_if($account === null, 404);

        $notice = $attention->notices($request->user(), $account)->whereKey($notification)->firstOrFail();
        $notice->markAsRead();

        return redirect()->to($this->sameHostPath($request, $notice->data['url'] ?? null) ?? route('notifications.index'));
    }

    /**
     * The path (and query) of a stored url, or null when it has none or
     * points at another host, so opening a notice never leaves the portal.
     */
    private function sameHostPath(Request $request, mixed $url): ?string
    {
        $parts = is_string($url) ? parse_url($url) : false;

        if ($parts === false || ! isset($parts['path'])) {
            return null;
        }

        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return null;
        }

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
