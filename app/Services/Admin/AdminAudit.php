<?php

namespace App\Services\Admin;

use App\Models\AdminAction;
use App\Models\User;

/**
 * The trail of what staff have done in the admin area.
 *
 * Every write an admin makes goes through here, so "who signed in as this
 * client" or "who suspended them" has an answer. The emails are copied onto
 * the row so it still reads after either login is gone.
 */
class AdminAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(User $admin, string $action, string $summary, ?User $subject = null, array $details = []): AdminAction
    {
        return AdminAction::create([
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'user_id' => $subject?->id,
            'user_email' => $subject?->email,
            'action' => $action,
            'summary' => $summary,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * The newest actions across the admin area, as the activity feed shows them.
     *
     * @return array<int, array{type: string, at: string, summary: string, actor: ?string}>
     */
    public function recent(int $limit = 50): array
    {
        return AdminAction::query()
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminAction $action): array => $this->present($action))
            ->all();
    }

    /**
     * The actions staff have taken on one person, newest first.
     *
     * @return array<int, array{type: string, at: string, summary: string, actor: ?string}>
     */
    public function forUser(User $user, int $limit = 25): array
    {
        return AdminAction::query()
            ->where('user_id', $user->id)
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminAction $action): array => $this->present($action))
            ->all();
    }

    /**
     * @return array{type: string, at: string, summary: string, actor: ?string}
     */
    private function present(AdminAction $action): array
    {
        return [
            'type' => 'admin_action',
            'at' => $action->created_at->toIso8601String(),
            'summary' => $action->summary,
            'actor' => $action->admin_email,
        ];
    }
}
