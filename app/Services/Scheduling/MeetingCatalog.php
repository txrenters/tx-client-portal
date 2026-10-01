<?php

namespace App\Services\Scheduling;

use App\Models\Setting;

/**
 * Which of tx-scheduling's meeting types clients may see and book.
 *
 * Anyone with a scheduling login can create a meeting type, and most are not
 * meant for clients, so the portal works from an allowlist staff keep on the
 * admin Meetings page. A type not on it never reaches the web page or the
 * app, including every one created after the list was last saved.
 */
class MeetingCatalog
{
    public const SETTING = 'meetings.allowed_event_types';

    /**
     * The list until staff first save their own: the four phone calls.
     *
     * @var array<int, string>
     */
    public const DEFAULT_SLUGS = [
        'accounting-team-meeting',
        'leasing-team-meeting-phone-call',
        'maintenance-team-meeting-phone-call',
        'new-to-market-meeting-phone-call',
    ];

    public function __construct(private SchedulingClient $scheduling) {}

    /**
     * The slugs clients may book. Empty means none, not all.
     *
     * @return array<int, string>
     */
    public function allowedSlugs(): array
    {
        $stored = Setting::valueOf(self::SETTING);

        if ($stored === null) {
            return self::DEFAULT_SLUGS;
        }

        $slugs = json_decode($stored, true);

        return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function setAllowed(array $slugs): void
    {
        Setting::put(self::SETTING, json_encode(array_values(array_unique($slugs)), JSON_THROW_ON_ERROR));
    }

    /**
     * The meeting types clients see, in scheduling's order.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SchedulingException
     */
    public function forClients(): array
    {
        $allowed = $this->allowedSlugs();

        return array_values(array_filter(
            $this->scheduling->eventTypes(),
            fn (array $type): bool => in_array($type['slug'] ?? null, $allowed, strict: true),
        ));
    }

    /**
     * Every meeting type in scheduling, each marked with whether clients see it.
     *
     * @return array<int, array{slug: string, name: string, description: ?string, durationMinutes: ?int, locationLabel: ?string, host: mixed, url: ?string, allowed: bool}>
     *
     * @throws SchedulingException
     */
    public function forStaff(): array
    {
        $allowed = $this->allowedSlugs();

        return array_map(fn (array $type): array => [
            'slug' => (string) ($type['slug'] ?? ''),
            'name' => (string) ($type['name'] ?? $type['slug'] ?? 'Untitled'),
            'description' => $type['description'] ?? null,
            'durationMinutes' => isset($type['durationMinutes']) ? (int) $type['durationMinutes'] : null,
            'locationLabel' => $type['locationLabel'] ?? null,
            'host' => $type['host'] ?? null,
            'url' => $type['url'] ?? null,
            'allowed' => in_array($type['slug'] ?? null, $allowed, strict: true),
        ], $this->scheduling->eventTypes());
    }
}
