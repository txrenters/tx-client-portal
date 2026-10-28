<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show which notifications the client receives.
     *
     * Texted replies are the messaging hub's to decide, so that switch reads
     * from the hub rather than from anything stored here.
     */
    public function edit(Request $request, ClientConversations $conversations): Response
    {
        $account = $this->currentClientAccount($request);

        return Inertia::render('settings/Notifications', [
            'preferences' => $request->user()->notificationPreferences(),
            'isOwner' => $request->user()->clientAccounts()->where('role', ClientRole::Owner)->exists(),
            'sms' => $account === null
                ? null
                : Inertia::defer(fn (): ?array => $conversations->smsReplies($account)),
        ]);
    }

    public function update(NotificationPreferencesRequest $request, ClientConversations $conversations): RedirectResponse
    {
        $request->user()->forceFill(['notification_preferences' => $request->preferences()])->save();

        $account = $this->currentClientAccount($request);
        $wanted = $request->smsReplies();

        if ($account !== null && $wanted !== null) {
            try {
                $conversations->setSmsReplies($account, $wanted);
            } catch (ChatHubException) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('We saved your other settings, but could not change texted replies. Please try again.')]);

                return to_route('notifications.edit');
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notifications.edit');
    }
}
