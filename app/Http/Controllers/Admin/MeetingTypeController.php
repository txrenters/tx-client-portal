<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAudit;
use App\Services\Scheduling\MeetingCatalog;
use App\Services\Scheduling\SchedulingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Choosing which of the scheduling app's meeting types owners can book.
 */
class MeetingTypeController extends Controller
{
    use FlashesToasts;

    public function index(MeetingCatalog $catalog): Response
    {
        return Inertia::render('admin/Meetings', [
            'meetingTypes' => Inertia::defer(function () use ($catalog): array {
                try {
                    return ['types' => $catalog->forStaff(), 'error' => null];
                } catch (SchedulingException $exception) {
                    return ['types' => [], 'error' => $exception->getMessage()];
                }
            }),
            // Approved types that are no longer in scheduling, so staff know.
            'allowedSlugs' => $catalog->allowedSlugs(),
        ]);
    }

    public function update(Request $request, MeetingCatalog $catalog, AdminAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'slugs' => ['present', 'array'],
            'slugs.*' => ['string', 'max:255'],
        ]);

        $catalog->setAllowed($validated['slugs']);

        $audit->record($request->user(), 'meeting_types_changed', 'Changed which meeting types clients can book', details: ['slugs' => $validated['slugs']]);
        $this->toast('Saved. Clients now see '.count($validated['slugs']).' meeting type(s).');

        return back();
    }
}
