<?php

namespace App\Services\Admin;

use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\HelpRequest;
use App\Models\PropertywareApproval;
use App\Models\User;
use App\Models\WorkOrderApproval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What has happened in the portal lately.
 *
 * Reads the timestamps the tables already carry — when accounts were created,
 * when invitations were accepted, when an owner approved a work order, when an
 * app session was last used — and merges them with the admin audit trail into
 * one feed. Client-side events only exist where they leave a row behind, so
 * only the staff actions amount to an audit log.
 *
 * Owners and admins also see the approvals and approval comments read from
 * Propertyware (ApprovalHistory), including ones made outside the portal.
 */
class PortalActivity
{
    public function __construct(private AdminAudit $audit) {}

    /**
     * The most recent events across the portal, newest first.
     *
     * @return array<int, array{type: string, at: string, summary: string, actor: ?string}>
     */
    public function recent(int $limit = 50, bool $withApprovalHistory = false): array
    {
        return $this->events($withApprovalHistory)
            ->filter(fn (array $event): bool => $event['at'] !== null)
            ->sortByDesc('at')
            ->take($limit)
            ->map(fn (array $event): array => [...$event, 'at' => (string) $event['at']])
            ->values()
            ->all();
    }

    /**
     * Every source of activity, unsorted.
     *
     * @return Collection<int, array{type: string, at: ?string, summary: string, actor: ?string}>
     */
    private function events(bool $withApprovalHistory): Collection
    {
        $signups = User::query()
            ->latest()
            ->limit(50)
            ->get(['name', 'email', 'created_at', 'email_verified_at'])
            ->flatMap(fn (User $user): array => array_filter([
                [
                    'type' => 'signup',
                    'at' => $user->created_at?->toIso8601String(),
                    'summary' => 'Created an account',
                    'actor' => $user->email,
                ],
                $user->email_verified_at === null ? null : [
                    'type' => 'verified',
                    'at' => $user->email_verified_at->toIso8601String(),
                    'summary' => 'Confirmed their email address',
                    'actor' => $user->email,
                ],
            ]));

        $links = ClientAccount::query()
            ->with('user:id,email')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ClientAccount $account): array => [
                'type' => 'account_linked',
                'at' => $account->created_at?->toIso8601String(),
                'summary' => 'Linked '.$account->role->article().' account',
                'actor' => $account->user?->email,
            ]);

        $invitations = ClientInvitation::query()
            ->whereNotNull('accepted_at')
            ->latest('accepted_at')
            ->limit(50)
            ->get(['email', 'role', 'accepted_at'])
            ->map(fn (ClientInvitation $invitation): array => [
                'type' => 'invitation_accepted',
                'at' => $invitation->accepted_at?->toIso8601String(),
                'summary' => 'Accepted '.$invitation->role->article().' invitation',
                'actor' => $invitation->email,
            ]);

        $approvals = WorkOrderApproval::query()
            // The App Review logins' approvals are not for staff.
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->latest('decided_at')
            ->limit(50)
            ->get()
            ->map(fn (WorkOrderApproval $approval): array => [
                'type' => 'work_order_'.$approval->decision,
                'at' => $approval->decided_at->toIso8601String(),
                'summary' => ucfirst((string) $approval->decision).' work order #'.$approval->work_order_id,
                'actor' => $approval->approver_name !== '' ? $approval->approver_name : null,
            ]);

        $sessions = collect(DB::table('personal_access_tokens')
            ->whereNotNull('last_used_at')
            ->orderByDesc('last_used_at')
            ->limit(50)
            ->get(['name', 'last_used_at']))
            ->map(fn (object $token): array => [
                'type' => 'app_session',
                'at' => (string) $token->last_used_at,
                'summary' => 'Used the mobile app',
                'actor' => null,
            ]);

        $helpRequests = HelpRequest::query()
            ->with('user:id,email')
            // The App Review logins' help requests are not for staff.
            ->whereDoesntHave('user', fn ($query) => $query->where('is_demo', true))
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (HelpRequest $request): array => [
                'type' => 'help_request',
                'at' => $request->created_at->toIso8601String(),
                'summary' => 'Asked for help: '.$request->topic->label(),
                'actor' => $request->user?->email,
            ]);

        $approvalHistory = ! $withApprovalHistory ? collect() : PropertywareApproval::query()
            ->latest('recorded_at')
            ->limit(50)
            ->get()
            ->map(fn (PropertywareApproval $approval): array => [
                'type' => 'propertyware_approval',
                'at' => $approval->recorded_at->toIso8601String(),
                'summary' => $this->approvalHistorySummary($approval),
                'actor' => $approval->approved_by,
            ]);

        return collect([$signups, $links, $invitations, $approvals, $approvalHistory, $sessions, $helpRequests, $this->audit->recent(50)])->flatten(1);
    }

    /**
     * How a work order's approval read in Propertyware, with its comment.
     */
    private function approvalHistorySummary(PropertywareApproval $approval): string
    {
        $summary = 'Work order #'.($approval->work_order_number ?? $approval->work_order_id)
            .($approval->approved ? ' approved in Propertyware' : ' not approved in Propertyware');

        return $approval->approval_comment !== null ? $summary.': "'.$approval->approval_comment.'"' : $summary;
    }
}
