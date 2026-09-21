<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    use ResolvesClientScope;

    /**
     * The client's notification settings, e.g. {"message_replies_email": true}.
     *
     * "sms" is null for a login with no client account, or when the messaging
     * hub can't be reached; it is the hub that decides what gets texted.
     */
    public function show(Request $request, ClientConversations $conversations): JsonResponse
    {
        $account = $this->currentClientAccount($request);

        return response()->json([
            'data' => $request->user()->notificationPreferences(),
            'meta' => ['sms' => $account === null ? null : $conversations->smsReplies($account)],
        ]);
    }

    /**
     * Change any of the settings; ones left out stay as they are.
     */
    public function update(NotificationPreferencesRequest $request, ClientConversations $conversations): JsonResponse
    {
        $request->user()->forceFill(['notification_preferences' => $request->preferences()])->save();

        $account = $this->currentClientAccount($request);
        $wanted = $request->smsReplies();

        if ($account !== null && $wanted !== null) {
            try {
                $conversations->setSmsReplies($account, $wanted);
            } catch (ChatHubException) {
                abort(503, 'We could not change texted replies right now.');
            }
        }

        return $this->show($request, $conversations);
    }
}
