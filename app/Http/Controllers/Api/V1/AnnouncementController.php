<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\Announcements\AnnouncementBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Staff announcements for the app's banner, and closing one.
 */
class AnnouncementController extends Controller
{
    /**
     * What is showing for this login now, newest first.
     */
    public function index(Request $request, AnnouncementBoard $board): JsonResponse
    {
        return response()->json(['data' => $board->forUser($request->user())]);
    }

    public function dismiss(Request $request, Announcement $announcement, AnnouncementBoard $board): Response
    {
        $board->dismiss($announcement, $request->user());

        return response()->noContent();
    }
}
