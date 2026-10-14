<?php

namespace App\Http\Controllers\Client;

use App\Enums\HelpTopic;
use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreHelpRequest;
use App\Models\HelpRequestAttachment;
use App\Services\Help\HelpDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Help page: ask staff for help with the portal or the app, and see what
 * became of earlier requests. The apps use the API's HelpRequestController.
 */
class HelpController extends Controller
{
    use FlashesToasts;

    public function index(Request $request, HelpDesk $desk): Response
    {
        return Inertia::render('client/Help', [
            'topics' => HelpTopic::options(),
            'requests' => Inertia::scroll(fn () => $desk->forUser($request->user(), max(1, $request->integer('page', 1)))),
        ]);
    }

    public function store(StoreHelpRequest $request, HelpDesk $desk): RedirectResponse
    {
        $desk->submit($request->user(), [
            'topic' => $request->validated('topic'),
            'message' => $request->validated('message'),
            'platform' => 'web',
        ], $request->userAgent(), $request->file('attachments', []));

        $this->toast(__('Thanks. Our team will look into it and get back to you.'));

        return back();
    }

    /**
     * A file the client attached to one of their own requests. Anyone else's
     * is a 404, so ids give nothing away.
     */
    public function attachment(Request $request, HelpRequestAttachment $attachment, HelpDesk $desk): StreamedResponse
    {
        abort_unless($attachment->helpRequest->user_id === $request->user()->id, 404);

        return $desk->attachmentResponse($attachment);
    }
}
