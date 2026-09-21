<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send the email verification link again.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('Your email address is already verified.')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('A new verification link has been sent to your email address.')], 202);
    }
}
