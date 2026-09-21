<?php

namespace App\Http\Controllers;

use App\Events\ClientConversationUpdated;
use App\Models\ClientAccount;
use App\Notifications\StaffRepliedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Receives events from the messaging hub (tx-chatbot) and tells the client's
 * open Messages page to refresh.
 *
 * Each event is signed with the shared secret as HMAC-SHA256 of
 * "{timestamp}.{body}"; stale or unsigned events are refused.
 */
class HubEventController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.chat_hub.webhook_secret');
        $timestamp = (string) $request->header('X-Hub-Timestamp');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        abort_if($secret === '' || ! ctype_digit($timestamp), 401);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature')), 401);
        abort_if(abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS, 401);

        $contactId = $request->input('data.thread.contact_id');
        $threadId = $request->input('data.thread.id');

        if (filled($contactId) && filled($threadId)) {
            ClientConversationUpdated::dispatch((string) $contactId, (int) $threadId);

            if ($request->input('event') === 'message.sent' && $request->input('data.message.sender') === 'agent'
                && $request->input('data.message.channel') === 'app') {
                $this->emailClient((string) $contactId, (int) $threadId, $request);
            }
        }

        return response()->noContent();
    }

    /**
     * Email each login linked to the contact that staff replied in the portal,
     * unless they turned these emails off, at most once per conversation every
     * half hour so a quick back-and-forth doesn't flood their inbox.
     */
    private function emailClient(string $contactId, int $threadId, Request $request): void
    {
        $accounts = ClientAccount::query()->with('user')->where('propertyware_id', $contactId)->get();

        foreach ($accounts as $account) {
            if ($account->user === null || ! $account->user->wantsNotification('message_replies_email') || ! Cache::add("hub:reply-email:{$account->user_id}:{$threadId}", true, now()->addMinutes(30))) {
                continue;
            }

            $account->user->notify(new StaffRepliedNotification(
                $account->role,
                $threadId,
                (string) ($request->input('data.thread.subject') ?? 'Your conversation'),
                $request->input('data.message.sender_name'),
                (string) ($request->input('data.message.body') ?? ''),
            ));
        }
    }
}
