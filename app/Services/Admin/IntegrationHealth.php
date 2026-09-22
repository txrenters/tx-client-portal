<?php

namespace App\Services\Admin;

use App\Services\ChatHub\ChatHubClient;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareSoap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether each service the portal depends on is actually answering.
 *
 * Every check is a read, and the cheapest read the service offers, so opening
 * the page cannot change anything at a vendor. Results are cached briefly so a
 * refresh does not hammer four APIs, and a check that is merely unconfigured is
 * reported separately from one that is configured and failing — only the second
 * is a problem worth chasing.
 */
class IntegrationHealth
{
    /** How long a set of results stands before the services are asked again. */
    private const CACHE_SECONDS = 60;

    public function __construct(
        private PropertywareClient $propertyware,
        private PropertywareSoap $soap,
        private ChatHubClient $hub,
    ) {}

    /**
     * Every integration, with its state and the reason when it is unhealthy.
     *
     * @return array<int, array{key: string, name: string, purpose: string, status: string, detail: ?string, checkedAt: string}>
     */
    public function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->cacheKey());
        }

        return Cache::remember($this->cacheKey(), now()->addSeconds(self::CACHE_SECONDS), fn (): array => [
            $this->check(
                'propertyware',
                'Propertyware REST',
                'Properties, leases, work orders, documents and the ledger.',
                $this->propertyware->isConfigured(),
                fn () => $this->propertyware->list('portfolios', [], 1),
            ),
            $this->check(
                'propertyware_soap',
                'Propertyware SOAP',
                'Owner work order approvals, the one write REST cannot do.',
                $this->soap->isConfigured(),
                // Nothing is called: SOAP's only operation is a write, so a
                // health check can confirm credentials exist and no more.
                null,
            ),
            $this->check(
                'chat_hub',
                'Chat hub',
                'Messages between clients and TexasRenters.com staff.',
                $this->hub->isConfigured(),
                fn () => $this->hub->ping(),
            ),
            $this->check(
                'database',
                'Database',
                'The portal\'s own accounts, ledger copy and queued work.',
                true,
                fn () => DB::select('select 1'),
            ),
        ]);
    }

    /**
     * How much queued work has failed, which is usually the first sign that an
     * integration broke while nobody was looking at this page.
     *
     * @return array{failed: int, pending: int, lastFailureAt: ?string}
     */
    public function queue(): array
    {
        $lastFailure = DB::table('failed_jobs')->max('failed_at');

        return [
            'failed' => DB::table('failed_jobs')->count(),
            'pending' => DB::table('jobs')->count(),
            'lastFailureAt' => $lastFailure === null ? null : (string) $lastFailure,
        ];
    }

    /**
     * Run one check, turning whatever it throws into a reportable state.
     *
     * @param  ?callable():mixed  $probe
     * @return array{key: string, name: string, purpose: string, status: string, detail: ?string, checkedAt: string}
     */
    private function check(string $key, string $name, string $purpose, bool $configured, ?callable $probe): array
    {
        $result = ['key' => $key, 'name' => $name, 'purpose' => $purpose, 'checkedAt' => now()->toIso8601String()];

        if (! $configured) {
            return [...$result, 'status' => 'not_configured', 'detail' => 'No credentials are set for this service.'];
        }

        if ($probe === null) {
            return [...$result, 'status' => 'configured', 'detail' => 'Credentials are set. There is no read to test them with.'];
        }

        try {
            $probe();

            return [...$result, 'status' => 'ok', 'detail' => null];
        } catch (Throwable $exception) {
            return [...$result, 'status' => 'failing', 'detail' => $exception->getMessage()];
        }
    }

    private function cacheKey(): string
    {
        return 'admin:integration-health';
    }
}
