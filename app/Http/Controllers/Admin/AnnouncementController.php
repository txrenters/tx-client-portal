<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Services\Admin\AdminAudit;
use App\Services\Announcements\AnnouncementBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notices to every client, or every owner or resident, on the web and in
 * the app, and optionally by email and push.
 */
class AnnouncementController extends Controller
{
    use FlashesToasts;

    public function __construct(private AnnouncementBoard $board, private AdminAudit $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/Announcements', [
            'announcements' => $this->board->forStaff(),
            'audienceSizes' => [
                'all' => $this->board->audienceSize('all'),
                'owner' => $this->board->audienceSize('owner'),
                'resident' => $this->board->audienceSize('resident'),
            ],
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = Announcement::create([...$request->announcement(), 'created_by' => $request->user()->id]);
        $sent = $this->board->send($announcement, $request->boolean('send_email'), $request->boolean('send_push'));

        $this->audit->record($request->user(), 'announcement_posted', "Posted the announcement \"{$announcement->title}\"");
        $this->toast($sent ? 'Announcement posted and on its way out.' : 'Announcement posted.');

        return back();
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->announcement());
        $sent = $this->board->send($announcement, $request->boolean('send_email'), $request->boolean('send_push'));

        $this->audit->record($request->user(), 'announcement_updated', "Changed the announcement \"{$announcement->title}\"");
        $this->toast($sent ? 'Announcement saved and on its way out.' : 'Announcement saved.');

        return back();
    }

    /**
     * Take an announcement down now, keeping it in the list.
     */
    public function end(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update(['ends_at' => now()]);
        $this->audit->record($request->user(), 'announcement_ended', "Took down the announcement \"{$announcement->title}\"");
        $this->toast('Announcement taken down.');

        return back();
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->delete();
        $this->audit->record($request->user(), 'announcement_deleted', "Deleted the announcement \"{$announcement->title}\"");
        $this->toast('Announcement deleted.');

        return back();
    }
}
