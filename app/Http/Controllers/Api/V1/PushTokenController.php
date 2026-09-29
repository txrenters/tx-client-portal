<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeletePushTokenRequest;
use App\Http\Requests\Api\V1\StorePushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The phones the app has asked us to ring.
 *
 * The app registers on every launch once notifications are allowed, and
 * retires the phone on sign-out so notifications meant for whoever just left
 * do not follow them onto the lock screen.
 */
class PushTokenController extends Controller
{
    /**
     * Register this phone, or move it to this login if another one had it.
     */
    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $device = PushToken::query()->updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->getKey(),
                'platform' => $request->string('platform')->toString(),
                'device_name' => $request->input('device_name'),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $device->id,
                'platform' => $device->platform,
                'deviceName' => $device->device_name,
                'registeredAt' => $device->created_at->toIso8601String(),
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Retire this phone. Only ever removes a token this login registered, so
     * one client cannot unregister another's device by guessing a token.
     */
    public function destroy(DeletePushTokenRequest $request): Response
    {
        $request->user()->pushTokens()
            ->where('token', $request->string('token')->toString())
            ->delete();

        return response()->noContent();
    }
}
