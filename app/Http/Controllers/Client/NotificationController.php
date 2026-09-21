<?php

namespace App\Http\Controllers\Client;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
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
    public function index(Request $request, PropertywareDirectory $directory, WorkOrderApprovals $approvals, ClientConversations $conversations): Response
    {
        $account = $this->currentClientAccount($request);
        $owner = $account?->role === ClientRole::Owner ? $account : null;

        return Inertia::render('client/Notifications', [
            'isOwner' => $owner !== null,
            'portal' => $account?->role->value,
            'approvals' => Inertia::defer(fn (): ?array => $this->summary($owner, $directory, $approvals, limit: 50)),
            'messages' => Inertia::defer(fn (): ?array => $this->unreadMessages($request, $account, $conversations, limit: 25)),
        ]);
    }

    /**
     * What the header bell's dropdown shows: how many items need attention and the first few.
     * Each section is null when its source couldn't be read.
     */
    public function latest(Request $request, PropertywareDirectory $directory, WorkOrderApprovals $approvals, ClientConversations $conversations): JsonResponse
    {
        $account = $this->currentClientAccount($request);
        $approvalSummary = $this->summary($account?->role === ClientRole::Owner ? $account : null, $directory, $approvals, limit: 5);
        $messages = $this->unreadMessages($request, $account, $conversations, limit: 5);

        return response()->json([
            'count' => ($approvalSummary['count'] ?? 0) + ($messages['count'] ?? 0),
            'portal' => $account?->role->value,
            // Listen here for new replies; the bell refreshes when one arrives.
            'channel' => $account === null ? null : "contacts.{$account->propertyware_id}.messages",
            'approvals' => $approvalSummary,
            'messages' => $messages,
        ]);
    }

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    private function unreadMessages(Request $request, ?ClientAccount $account, ClientConversations $conversations, int $limit): ?array
    {
        if ($account === null || ! $conversations->isAvailable()) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $conversations->unread($request->user(), $account, $limit);
        } catch (ChatHubException) {
            return null;
        }
    }

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>}|null
     */
    private function summary(?ClientAccount $account, PropertywareDirectory $directory, WorkOrderApprovals $approvals, int $limit): ?array
    {
        if ($account === null) {
            return ['count' => 0, 'items' => []];
        }

        try {
            return $approvals->summaryFor($directory->scopeFor($account), $account, $limit);
        } catch (PropertywareException) {
            return null;
        }
    }
}
