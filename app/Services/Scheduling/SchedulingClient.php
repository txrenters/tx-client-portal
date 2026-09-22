<?php

namespace App\Services\Scheduling;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to tx-scheduling, which owns meetings.
 *
 * Booking itself stays there: its own page already knows about timezones,
 * the questions each meeting asks, extra guests and rescheduling. The portal
 * asks what is on offer and what this client has booked, and links out for
 * the rest, rather than keeping a second answer that would drift.
 */
class SchedulingClient
{
    /**
     * @param  bool|string  $verify  Whether to check TLS, or a CA bundle path for local .test sites.
     */
    public function __construct(
        private ?string $url,
        private ?string $token,
        private ?string $page,
        private bool|string $verify = true,
    ) {}

    /** How long the key's own organization is remembered. */
    private const PAGE_CACHE_HOURS = 12;

    public function isConfigured(): bool
    {
        return filled($this->url) && filled($this->token);
    }

    /**
     * The booking page to ask about.
     *
     * A key is issued for one organization, so it already says which page it
     * speaks for: the portal asks once and remembers, rather than being told
     * the same thing twice and risking the two disagreeing. A configured page
     * still wins, which is what a system-wide key needs.
     *
     * @throws SchedulingException
     */
    public function page(): string
    {
        if (filled($this->page)) {
            return (string) $this->page;
        }

        $page = Cache::remember(
            'scheduling:page:'.md5((string) $this->token),
            now()->addHours(self::PAGE_CACHE_HOURS),
            fn (): ?string => $this->send(fn (PendingRequest $request): Response => $request->get('me'))
                ->json('data.organization.page'),
        );

        if (blank($page)) {
            throw new SchedulingException('This key is not tied to an organization, so a booking page must be configured.');
        }

        return (string) $page;
    }

    /**
     * Every meeting staff offer, across all of the organization's pages.
     *
     * Shared meetings are advertised on the organization's page and a
     * person's own on theirs, so asking a single page would show only part of
     * what is on offer. Each one carries the page it is booked on.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SchedulingException
     */
    public function eventTypes(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request
            ->get("organizations/{$this->page()}/event-types"))->json('data') ?? [];
    }

    /**
     * What this client has coming up.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SchedulingException
     */
    public function upcoming(string $email): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request
            ->get('bookings', ['email' => $email]))->json('data') ?? [];
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws SchedulingException
     */
    private function send(callable $request): Response
    {
        if (! $this->isConfigured()) {
            throw new SchedulingException('Scheduling is not configured.');
        }

        try {
            $response = $request(Http::baseUrl(rtrim((string) $this->url, '/').'/api/v1/')
                ->withToken((string) $this->token)
                ->acceptJson()
                ->withOptions(['verify' => $this->verify])
                ->timeout(10)
                ->connectTimeout(5));
        } catch (ConnectionException $exception) {
            throw new SchedulingException('Could not reach the scheduling app.', previous: $exception);
        }

        if ($response->failed()) {
            throw new SchedulingException("The scheduling app answered {$response->status()}.");
        }

        return $response;
    }
}
