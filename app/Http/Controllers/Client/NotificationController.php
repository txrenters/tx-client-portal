<?php

namespace App\Http\Controllers\Client;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NeedsAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'count' => ($approvalSummary['count'] ?? 0) + ($messages['count'] ?? 0),
            'portal' => $account?->role->value,
            // Listen here for new replies; the bell refreshes when one arrives.
            'channel' => $account === null ? null : "contacts.{$account->propertyware_id}.messages",
            'approvals' => $approvalSummary,
            'messages' => $messages,
        ]);
    }
}
