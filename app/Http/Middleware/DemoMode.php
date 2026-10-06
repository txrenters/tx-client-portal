<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use App\Services\ChatHub\ChatHubClient;
use App\Services\Demo\DemoBackend;
use App\Services\Demo\DemoPropertywareSoap;
use App\Services\Demo\DemoReset;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\LeaseLedgerSource;
use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareSoap;
use App\Services\Push\ExpoPushClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an App Review login made-up records instead of Propertyware, the
 * chat hub and the other outside systems, for the rest of its request.
 *
 * Every change here is to the container and config of this one request. That
 * is safe because the portal runs on PHP-FPM, a fresh process per request.
 * Under Octane or any long-lived worker it would leak into the next request,
 * so revisit this before adopting one.
 */
class DemoMode
{
    /**
     * The outside clients, and the services built on them, rebuilt once their config points at the demo.
     *
     * @var array<int, class-string>
     */
    private const REBUILT = [
        PropertywareClient::class, ChatHubClient::class, ExpoPushClient::class,
        PropertywareDirectory::class, Financials::class, LeaseLedgerSource::class, LeaseLedgerStore::class,
        LeaseLedger::class, DocumentLibrary::class, MaintenanceSummary::class,
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // The app's token is resolved by the route's auth middleware, which
        // runs after this group, so ask the Sanctum guard directly.
        $user = $request->user() ?? $request->user('sanctum');

        if ($user instanceof User && $user->is_demo) {
            self::enter();
        }

        return $next($request);
    }

    public static function cacheStore(): string
    {
        return 'demo';
    }

    /**
     * Switch the current request to the demo.
     */
    public static function enter(): void
    {
        $default = config('cache.default');

        // Captured before the config below is overwritten: the real systems a demo request must never reach.
        $real = collect(['services.propertyware.base_url', 'services.chat_hub.url', 'services.tx_cma.url', 'services.leads.webhook_url', 'services.expo.url'])
            ->map(fn (string $key): mixed => parse_url((string) config($key), PHP_URL_HOST))
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '' && ! str_ends_with($host, '.demo.invalid'))
            ->values()
            ->all();

        config([
            'services.propertyware.base_url' => 'https://'.DemoBackend::PROPERTYWARE,
            'services.propertyware.client_id' => 'demo',
            'services.propertyware.client_secret' => 'demo',
            'services.propertyware.system_id' => 'demo',
            'services.propertyware.discover_accounts' => false,
            'services.chat_hub.url' => 'https://'.DemoBackend::CHAT_HUB.'/api/v1',
            'services.chat_hub.token' => 'demo',
            'services.tx_cma.url' => 'https://'.DemoBackend::LISTINGS,
            'services.tx_cma.token' => 'demo',
            'services.leads.webhook_url' => 'https://'.DemoBackend::LEADS.'/referrals',
            'services.expo.url' => 'https://'.DemoBackend::EXPO,
            // The same store under its own prefix, so a shared key such as the
            // account-wide building list never mixes demo and real records.
            'cache.stores.'.self::cacheStore() => [...config("cache.stores.{$default}"), 'prefix' => 'demo_'.Setting::valueOf(DemoReset::CACHE_GENERATION, '0').'_'],
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ]);

        foreach (self::REBUILT as $service) {
            app()->forgetInstance($service);
        }

        app()->instance(PropertywareSoap::class, new DemoPropertywareSoap);
        Cache::setDefaultDriver(self::cacheStore());

        $backend = app(DemoBackend::class);
        $backend->blocking($real);
        Http::fake($backend->respond(...));
    }
}
