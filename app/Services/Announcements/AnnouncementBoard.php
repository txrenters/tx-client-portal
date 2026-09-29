<?php

namespace App\Services\Announcements;

use App\Enums\ClientRole;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Notices from TexasRenters.com to everyone on the portal, or to all owners
 * or all residents: shown as a banner on the web and in the app until the
 * client closes it, and optionally emailed or pushed once.
 */
class AnnouncementBoard
{
    /**
     * Every announcement, newest first, with how far it has reached.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forStaff(): array
    {
        return Announcement::query()
            ->with('author:id,email')
            ->withCount('dismissals')
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn (Announcement $announcement): array => [
                ...$this->present($announcement),
                'startsAt' => $announcement->starts_at?->toIso8601String(),
                'endsAt' => $announcement->ends_at?->toIso8601String(),
                'isLive' => $announcement->isLive(),
                'emailedAt' => $announcement->emailed_at?->toIso8601String(),
                'pushedAt' => $announcement->pushed_at?->toIso8601String(),
                'dismissals' => $announcement->dismissals_count,
                'createdBy' => $announcement->author?->email,
            ])
            ->all();
    }

    /**
     * What is showing for this person now, leaving out what they closed.
     *
     * @return array<int, array{id: int, title: string, body: string, tone: string, audience: string, postedAt: string}>
     */
    public function forUser(User $user): array
    {
        $roles = $user->clientRoles();

        if ($roles->isEmpty()) {
            return [];
        }

        return Announcement::query()
            ->live()
            ->forRoles($roles)
            ->whereDoesntHave('dismissals', fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (Announcement $announcement): array => $this->present($announcement))
            ->all();
    }

    /**
     * @return array{id: int, title: string, body: string, tone: string, audience: string, postedAt: string}
     */
    public function present(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'tone' => $announcement->tone,
            'audience' => $announcement->audience,
            'postedAt' => ($announcement->starts_at ?? $announcement->created_at)->toIso8601String(),
        ];
    }

    /**
     * Close an announcement for this person on every device.
     */
    public function dismiss(Announcement $announcement, User $user): void
    {
        AnnouncementDismissal::query()->firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $user->id]);
    }

    /**
     * Queue the email and/or push for an announcement, once each.
     *
     * Only what has not gone yet is queued, so saving an edit and asking again
     * never reaches anyone twice through the same channel.
     */
    public function send(Announcement $announcement, bool $email, bool $push): bool
    {
        $email = $email && $announcement->emailed_at === null;
        $push = $push && $announcement->pushed_at === null;

        if (! $email && ! $push) {
            return false;
        }

        $announcement->forceFill(array_filter([
            'emailed_at' => $email ? now() : null,
            'pushed_at' => $push ? now() : null,
        ]))->save();

        SendAnnouncement::dispatch($announcement->id, $email, $push);

        return true;
    }

    /**
     * How many logins an announcement for this audience reaches.
     */
    public function audienceSize(string $audience): int
    {
        return $this->audienceQuery($audience)->count();
    }

    /**
     * Confirmed, active logins holding an account in the audience.
     *
     * @return Builder<User>
     */
    public function audienceQuery(string $audience): Builder
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->whereNull('suspended_at')
            ->whereHas('clientAccounts', fn ($query) => $audience === 'all'
                ? $query
                : $query->where('role', ClientRole::from($audience)->value));
    }
}
