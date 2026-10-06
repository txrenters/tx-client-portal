<?php

namespace App\Services\Demo;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Answers every outside call a demo login's request makes, by host.
 *
 * DemoMode points each service at one of these hosts. They are under the
 * reserved .invalid domain, so a call that ever escaped the fake could only
 * fail to resolve. Any other host is left alone (null): scheduling, which holds
 * no client records, stays real.
 */
final class DemoBackend
{
    public const PROPERTYWARE = 'propertyware.demo.invalid';

    public const CHAT_HUB = 'chat-hub.demo.invalid';

    public const LISTINGS = 'tx-cma.demo.invalid';

    public const LEADS = 'leads.demo.invalid';

    public const EXPO = 'expo.demo.invalid';

    /**
     * The real hosts of the outside systems, which a demo request must never reach.
     *
     * @var array<int, string>
     */
    private array $blocked = [];

    public function __construct(private DemoPropertyware $propertyware, private DemoChatHub $chatHub) {}

    /**
     * Refuse these hosts. DemoMode hands over the hosts the outside systems
     * were configured with before the switch, so a service that kept its old
     * config still cannot reach the real system.
     *
     * @param  array<int, string>  $hosts
     */
    public function blocking(array $hosts): void
    {
        $this->blocked = $hosts;
    }

    public function respond(Request $request): ?PromiseInterface
    {
        $host = parse_url($request->url(), PHP_URL_HOST);

        if (is_string($host) && in_array($host, $this->blocked, true)) {
            return Http::response(['message' => 'Not available in the demo.'], 404);
        }

        return match ($host) {
            self::PROPERTYWARE => $this->propertyware->respond($request),
            self::CHAT_HUB => $this->chatHub->respond($request),
            // No listing photos for made-up homes.
            self::LISTINGS => Http::response(['match_type' => 'none']),
            // A referral from a demo login is accepted and dropped.
            self::LEADS => Http::response(['ok' => true]),
            // Pushes to a demo login's phone are dropped.
            self::EXPO => Http::response(['data' => []]),
            default => null,
        };
    }
}
