<?php

namespace App\Services\Help;

use App\Enums\HelpRequestStatus;
use App\Enums\HelpTopic;
use App\Models\HelpRequest;
use App\Models\HelpRequestAttachment;
use App\Models\User;
use App\Notifications\HelpRequestReceivedNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Requests for help with the portal and the apps, from the client's Help page
 * on the web and in the app, to the staff inbox in the admin area.
 *
 * Only about the software: a leak or a question about rent goes through
 * Messages or Maintenance, where the right team already looks.
 */
class HelpDesk
{
    /** Requests on one page of a list. */
    private const PER_PAGE = 20;

    /** Where attachments live on the private local disk. */
    private const ATTACHMENT_DISK = 'local';

    /**
     * Take a request with any screenshots that came with it, and tell staff about it.
     *
     * @param  array{topic: string, message: string, platform: string, app_version?: ?string}  $details
     * @param  array<int, UploadedFile>  $attachments
     */
    public function submit(User $user, array $details, ?string $userAgent = null, array $attachments = []): HelpRequest
    {
        $request = $user->helpRequests()->create([
            'topic' => HelpTopic::from($details['topic']),
            'message' => $details['message'],
            'platform' => $details['platform'],
            'app_version' => $details['app_version'] ?? null,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            'status' => HelpRequestStatus::Open,
        ]);

        foreach ($attachments as $file) {
            $request->attachments()->create([
                'path' => $file->store('help-requests/'.$request->id, self::ATTACHMENT_DISK),
                'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }

        $staff = array_filter((array) config('services.admin_emails', []));

        // The App Review logins are only for trying the form out; staff are never told.
        if ($staff !== [] && ! $user->is_demo) {
            Notification::route('mail', $staff)->notify(new HelpRequestReceivedNotification($request));
        }

        return $request;
    }

    /**
     * The person's own requests, newest first.
     *
     * @return LengthAwarePaginator<int, array{id: int, topic: string, topicLabel: string, message: string, platform: string, status: string, statusLabel: string, staffNote: ?string, resolvedAt: ?string, sentAt: string, attachments: list<array{id: int, name: string, mimeType: string, size: int, isImage: bool, url: string}>}>
     */
    public function forUser(User $user, int $page = 1, string $attachmentRoute = 'help.attachments.show'): LengthAwarePaginator
    {
        return $user->helpRequests()
            ->with('attachments')
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE, page: $page)
            ->through(fn (HelpRequest $request): array => $this->present($request, $attachmentRoute));
    }

    /**
     * One request as the client sees it.
     *
     * @return array{id: int, topic: string, topicLabel: string, message: string, platform: string, status: string, statusLabel: string, staffNote: ?string, resolvedAt: ?string, sentAt: string, attachments: list<array{id: int, name: string, mimeType: string, size: int, isImage: bool, url: string}>}
     */
    public function present(HelpRequest $request, string $attachmentRoute = 'help.attachments.show'): array
    {
        return [
            'id' => $request->id,
            'topic' => $request->topic->value,
            'topicLabel' => $request->topic->label(),
            'message' => $request->message,
            'platform' => $request->platform,
            'status' => $request->status->value,
            'statusLabel' => $request->status->label(),
            // Staff write the note for the client, and it is only shown once
            // the request is settled, so a half-written one never leaks.
            'staffNote' => $request->status === HelpRequestStatus::Resolved ? $request->staff_note : null,
            'resolvedAt' => $request->resolved_at?->toIso8601String(),
            'sentAt' => $request->created_at->toIso8601String(),
            // Each behind a route that checks who is asking: the web page, the
            // apps and the admin area each name their own.
            'attachments' => $request->attachments->map(fn (HelpRequestAttachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mimeType' => $attachment->mime_type,
                'size' => $attachment->size,
                'isImage' => $attachment->isImage(),
                'url' => route($attachmentRoute, $attachment),
            ])->values()->all(),
        ];
    }

    /**
     * An attachment's file, shown in the browser rather than downloaded.
     */
    public function attachmentResponse(HelpRequestAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Every request, for staff, optionally narrowed to one status or a search.
     *
     * @return LengthAwarePaginator<int, array{id: int, topic: string, topicLabel: string, message: string, platform: string, status: string, statusLabel: string, staffNote: ?string, resolvedAt: ?string, sentAt: string, attachments: list<array{id: int, name: string, mimeType: string, size: int, isImage: bool, url: string}>, appVersion: ?string, userAgent: ?string, resolvedBy: ?string, user: array{id: int, name: string, email: string}}>
     */
    public function forStaff(?HelpRequestStatus $status = null, ?string $search = null): LengthAwarePaginator
    {
        return HelpRequest::query()
            ->with(['user:id,name,email', 'resolver:id,email', 'attachments'])
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('lower(message) like ?', [$term])
                        ->orWhereHas('user', function ($query) use ($term): void {
                            $query->whereRaw('lower(name) like ?', [$term])
                                ->orWhereRaw('lower(email) like ?', [$term]);
                        });
                });
            })
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (HelpRequest $request): array => [
                ...$this->present($request, 'admin.help-requests.attachments.show'),
                'staffNote' => $request->staff_note,
                'appVersion' => $request->app_version,
                'userAgent' => $request->user_agent,
                'resolvedBy' => $request->resolver?->email,
                'user' => [
                    'id' => $request->user->id,
                    'name' => $request->user->name,
                    'email' => $request->user->email,
                ],
            ]);
    }

    /**
     * How many requests are waiting on staff.
     */
    public function openCount(): int
    {
        return HelpRequest::query()
            ->where('status', HelpRequestStatus::Open->value)
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->count();
    }

    /**
     * Settle a request, with a note the client will see.
     */
    public function resolve(HelpRequest $request, User $staff, ?string $note): void
    {
        $request->update([
            'status' => HelpRequestStatus::Resolved,
            'staff_note' => filled($note) ? $note : null,
            'resolved_at' => now(),
            'resolved_by' => $staff->id,
        ]);
    }

    /**
     * Put a settled request back in the queue.
     */
    public function reopen(HelpRequest $request): void
    {
        $request->update([
            'status' => HelpRequestStatus::Open,
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
    }
}
