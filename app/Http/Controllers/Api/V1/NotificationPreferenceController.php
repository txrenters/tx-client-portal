<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    /**
     * The client's notification settings, e.g. {"message_replies_email": true}.
     *
     * "sms" is always null now that clients cannot turn texts off; apps
     * already installed hide their texts switch when it is.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->notificationPreferences(),
            'meta' => ['sms' => null],
        ]);
    }

    /**
     * Change any of the settings; ones left out stay as they are.
     */
    public function update(NotificationPreferencesRequest $request): JsonResponse
    {
        $request->user()->forceFill(['notification_preferences' => $request->preferences()])->save();

        return $this->show($request);
    }
}
