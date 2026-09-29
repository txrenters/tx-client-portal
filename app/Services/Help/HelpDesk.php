<?php

namespace App\Services\Help;

use App\Enums\HelpRequestStatus;
use App\Enums\HelpTopic;
use App\Models\HelpRequest;
use App\Models\User;
use App\Notifications\HelpRequestReceivedNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;

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

    /**
     * Take a request, and tell staff about it.
     *
     * @param  array{topic: string, message: string, platform: string, app_version?: ?string}  $details
     */
    public function submit(User $user, array $details, ?string $userAgent = null): HelpRequest
    {
        $request = $user->helpRequests()->create([
            'topic' => HelpTopic::from($details['topic']),
            'message' => $details['message'],
            'platform' => $details['platform'],
            'app_version' => $details['app_version'] ?? null,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            'status' => HelpRequestStatus::Open,
        ]);

        $staff = array_filter((array) config('services.admin_emails', []));

        if ($staff !== []) {
            Notification::route('mail', $staff)->notify(new HelpRequestReceivedNotification($request));
        }

        return $request;
    }

    /**
     * The person's own requests, newest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forUser(User $user, int $page = 1): LengthAwarePaginator
    {
        return $user->helpRequests()
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE, page: $page)
            ->through(fn (HelpRequest $request): array => $this->present($request));
    }

    /**
     * One request as the client sees it.
     *
     * @return array<string, mixed>
     */
    public function present(HelpRequest $request): array
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
        ];
    }

    /**
     * Every request, for staff, optionally narrowed to one status or a search.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forStaff(?HelpRequestStatus $status = null, ?string $search = null): LengthAwarePaginator
    {
        return HelpRequest::query()
            ->with(['user:id,name,email', 'resolver:id,email'])
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
                ...$this->present($request),
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
        return HelpRequest::query()->where('status', HelpRequestStatus::Open->value)->count();
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
