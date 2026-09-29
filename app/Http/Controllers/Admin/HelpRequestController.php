<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpRequestStatus;
use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\HelpRequest;
use App\Services\Admin\AdminAudit;
use App\Services\Help\HelpDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What clients have asked for help with, from the Help page on the web and in
 * the apps.
 */
class HelpRequestController extends Controller
{
    use FlashesToasts;

    public function index(Request $request, HelpDesk $desk): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([...array_column(HelpRequestStatus::cases(), 'value'), 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        // Open by default, since the inbox is for what still needs doing;
        // "all" asks for everything.
        $status = match ($validated['status'] ?? null) {
            null => HelpRequestStatus::Open,
            'all' => null,
            default => HelpRequestStatus::from($validated['status']),
        };
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/HelpRequests', [
            'status' => $status?->value ?? 'all',
            'search' => $search,
            'openCount' => $desk->openCount(),
            'requests' => $desk->forStaff($status, $search === '' ? null : $search),
        ]);
    }

    /**
     * Mark a request resolved with a note for the client, or open it again.
     */
    public function update(Request $request, HelpRequest $helpRequest, HelpDesk $desk, AdminAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(HelpRequestStatus::class)],
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $client = $helpRequest->user;

        if ($validated['status'] === HelpRequestStatus::Resolved->value) {
            $desk->resolve($helpRequest, $request->user(), $validated['staff_note'] ?? null);
            $audit->record($request->user(), 'help_request_resolved', "Resolved a help request from {$client->email}", $client);
            $this->toast('Marked as resolved.');
        } else {
            $desk->reopen($helpRequest);
            $audit->record($request->user(), 'help_request_reopened', "Reopened a help request from {$client->email}", $client);
            $this->toast('Opened again.');
        }

        return back();
    }
}
