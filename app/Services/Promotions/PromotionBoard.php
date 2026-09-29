<?php

namespace App\Services\Promotions;

use App\Enums\ClientRole;
use App\Models\Promotion;
use App\Models\PromotionView;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which promotion, if any, pops up for a client today, and what they did
 * with it. The web portal and the apps ask the same question, so a popup
 * seen on the phone counts for the web that day too.
 *
 * A promotion shows at most once a day per person, and stops for good when
 * they turn it off, when staff end it, or (for referral promotions) once they
 * have referred someone.
 */
class PromotionBoard
{
    /** What the client did with a popup. */
    public const ACTIONS = ['seen', 'click', 'hide'];

    /**
     * The promotion due for this person today, or null.
     *
     * @return array{id: int, title: string, body: string, buttonLabel: string, theme: string, icon: string, link: array{type: string, url?: string}}|null
     */
    public function popupFor(User $user, ClientRole $role): ?array
    {
        $now = now();
        $today = $this->today($now);
        $hasReferred = null;

        $candidates = Promotion::query()
            ->where('is_active', true)
            ->whereIn('audience', ['all', $role->value])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        $views = PromotionView::query()
            ->where('user_id', $user->id)
            ->whereIn('promotion_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('promotion_id');

        foreach ($candidates as $promotion) {
            $view = $views->get($promotion->id);

            if ($view?->hidden_at !== null || $view?->last_shown_on?->toDateString() === $today) {
                continue;
            }

            if ($promotion->stop_after_referral) {
                $hasReferred ??= Referral::query()->where('user_id', $user->id)->exists();

                if ($hasReferred) {
                    continue;
                }
            }

            return $this->present($promotion);
        }

        return null;
    }

    /**
     * Record that the popup was shown, tapped, or turned off for good.
     */
    public function record(Promotion $promotion, User $user, string $action): void
    {
        $view = PromotionView::query()->firstOrNew(['promotion_id' => $promotion->id, 'user_id' => $user->id]);

        match ($action) {
            'seen' => $view->fill([
                'last_shown_on' => $this->today(now()),
                'times_shown' => $view->times_shown + ($view->last_shown_on?->toDateString() === $this->today(now()) ? 0 : 1),
            ]),
            'click' => $view->fill(['clicked_at' => $view->clicked_at ?? now()]),
            'hide' => $view->fill(['hidden_at' => $view->hidden_at ?? now()]),
        };

        $view->save();
    }

    /**
     * Every promotion with how it has done, newest first, for staff.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forStaff(): Collection
    {
        return Promotion::query()
            ->withCount([
                'views as people_shown',
                'views as people_clicked' => fn ($query) => $query->whereNotNull('clicked_at'),
                'views as people_hidden' => fn ($query) => $query->whereNotNull('hidden_at'),
            ])
            ->orderByDesc('is_active')
            ->latest()
            ->get()
            ->map(fn (Promotion $promotion): array => [
                ...$this->present($promotion),
                'buttonLink' => $promotion->button_link,
                'audience' => $promotion->audience,
                'startsAt' => $promotion->starts_at?->toIso8601String(),
                'endsAt' => $promotion->ends_at?->toIso8601String(),
                'isActive' => $promotion->is_active,
                'priority' => $promotion->priority,
                'stopAfterReferral' => $promotion->stop_after_referral,
                'stats' => [
                    'shown' => (int) $promotion->getAttribute('people_shown'),
                    'clicked' => (int) $promotion->getAttribute('people_clicked'),
                    'hidden' => (int) $promotion->getAttribute('people_hidden'),
                ],
            ]);
    }

    /**
     * @return array{id: int, title: string, body: string, buttonLabel: string, link: array{type: string, url?: string}}
     */
    public function present(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'title' => $promotion->title,
            'body' => $promotion->body,
            'buttonLabel' => $promotion->button_label,
            // Added in the design update; older app builds ignore them.
            'theme' => $promotion->theme ?? 'blue',
            'icon' => $promotion->icon ?? 'gift',
            'link' => $promotion->button_link === Promotion::LINK_REFERRALS
                ? ['type' => 'referrals']
                : ['type' => 'url', 'url' => $promotion->button_link],
        ];
    }

    /**
     * The office's calendar day, so "once a day" turns over at midnight in Texas.
     */
    private function today(CarbonInterface $now): string
    {
        return $now->copy()->setTimezone(config('app.display_timezone'))->toDateString();
    }
}
