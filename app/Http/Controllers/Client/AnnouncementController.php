<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\Announcements\AnnouncementBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Closing an announcement banner. The banners themselves are shared with
 * every page by HandleInertiaRequests.
 */
class AnnouncementController extends Controller
{
    public function dismiss(Request $request, Announcement $announcement, AnnouncementBoard $board): RedirectResponse
    {
        $board->dismiss($announcement, $request->user());

        return back();
    }
}
