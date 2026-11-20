<?php

namespace App\Services\Scheduling;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Which departments list each meeting, read from coresched's departments page.
 *
 * Coresched's API does not say which department a meeting belongs to, but
 * the public departments page the portal already links to does: it lists
 * each department, and each department's own page lists its meetings. Both
 * are Inertia pages, so their data sits as JSON in the HTML. The answer is
 * remembered for a while, and anything that cannot be read leaves the
 * meetings ungrouped rather than breaking the page.
 */
class DepartmentDirectory
{
    private const CACHE_KEY = 'scheduling:departments:';

    private const CACHE_MINUTES = 15;

    /** The most department pages read, whatever the departments page lists. */
    private const MAX_DEPARTMENTS = 20;

    /** How long a failed read is remembered, so an outage is not retried on every visit. */
    private const FAILURE_CACHE_MINUTES = 1;

    public function __construct(
        private ?string $departmentsUrl,
        private bool|string $verify = true,
    ) {}

    /**
     * Department names by meeting slug, in the departments page's order.
     *
     * @return array<string, array<int, string>>
     */
    public function bySlug(): array
    {
        if (blank($this->departmentsUrl)) {
            return [];
        }

        $key = self::CACHE_KEY.md5((string) $this->departmentsUrl);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $departments = $this->read();

        Cache::put(
            $key,
            $departments ?? [],
            now()->addMinutes($departments === null ? self::FAILURE_CACHE_MINUTES : self::CACHE_MINUTES),
        );

        return $departments ?? [];
    }

    /**
     * Read the departments page and each department's page.
     *
     * @return array<string, array<int, string>>|null Null when the departments page cannot be read.
     */
    private function read(): ?array
    {
        $page = $this->props((string) $this->departmentsUrl);

        if (! is_array($page['departments'] ?? null)) {
            return null;
        }

        $bySlug = [];

        foreach (array_slice($page['departments'], 0, self::MAX_DEPARTMENTS) as $department) {
            $name = trim((string) ($department['name'] ?? ''));
            $url = (string) ($department['url'] ?? '');

            // The links come from a page the portal does not control, so only
            // ones on the departments page's own site are followed.
            if ($name === '' || ! $this->onBookingSite($url)) {
                continue;
            }

            foreach ($this->props($url)['eventTypes'] ?? [] as $eventType) {
                $slug = (string) ($eventType['slug'] ?? '');

                if ($slug !== '' && ! in_array($name, $bySlug[$slug] ?? [], true)) {
                    $bySlug[$slug][] = $name;
                }
            }
        }

        return $bySlug;
    }

    /**
     * Whether an address is https on the same host as the departments page.
     */
    private function onBookingSite(string $url): bool
    {
        $host = parse_url((string) $this->departmentsUrl, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($host)
            && strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) === 0;
    }

    /**
     * The Inertia props a coresched booking page was rendered with.
     *
     * @return array<string, mixed>
     */
    private function props(string $url): array
    {
        try {
            // Redirects are not followed, so a link cannot hop to another site.
            $response = Http::withOptions(['verify' => $this->verify, 'allow_redirects' => false])
                ->timeout(10)
                ->connectTimeout(5)
                ->get($url);
        } catch (ConnectionException) {
            return [];
        }

        if ($response->failed()
            || preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $response->body(), $match) !== 1) {
            return [];
        }

        $page = json_decode($match[1], true);

        return is_array($page['props'] ?? null) ? $page['props'] : [];
    }
}
