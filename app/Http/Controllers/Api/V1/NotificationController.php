<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Notifications\NeedsAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * The app's bell: stored notices for this account, plus the unread replies
     * and approvals the web bell counts.
     */
    public function index(Request $request, ClientAccount $account, NeedsAttention $attention): JsonResponse
    {
        $notices = $attention->notices($request->user(), $account)->latest()->limit(50)->get();
        $messages = $attention->messages($request->user(), $account, 25);
        $approvals = $attention->approvals($account->role === ClientRole::Owner ? $account : null, 50);

        return response()->json([
            'data' => $notices->map(fn (DatabaseNotification $notice): array => NeedsAttention::present($notice))->values(),
            'meta' => [
                'unread' => $attention->notices($request->user(), $account)->whereNull('read_at')->count()
                    + ($messages['count'] ?? 0) + ($approvals['count'] ?? 0),
                'messages' => $messages,
                'approvals' => $approvals,
            ],
        ]);
    }

    /**
     * Mark one of this account's notices read. Any other id answers as missing.
     */
    public function read(Request $request, ClientAccount $account, string $notification, NeedsAttention $attention): Response
    {
        $attention->notices($request->user(), $account)->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    /**
     * Mark every notice of this account read, leaving the user's other accounts alone.
     */
    public function readAll(Request $request, ClientAccount $account, NeedsAttention $attention): Response
    {
        $attention->notices($request->user(), $account)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }
}
