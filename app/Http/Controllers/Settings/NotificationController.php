<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Show which notifications the client receives. Texts are not among
     * them: whether a message is texted is TexasRenters.com's to decide.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'preferences' => $request->user()->notificationPreferences(),
        ]);
    }

    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        $request->user()->forceFill(['notification_preferences' => $request->preferences()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notifications.edit');
    }
}
