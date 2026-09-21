<?php

namespace App\Http\Middleware;

use App\Actions\Client\SyncClientAccounts;
use App\Services\Propertyware\PropertywareException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncPropertywareAccounts
{
    public function __construct(private SyncClientAccounts $syncAccounts) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('services.propertyware.discover_accounts') || $user === null || $user->email_verified_at === null || ! $request->isMethod('GET') || $request->hasHeader('X-Inertia-Partial-Data')) {
            return $next($request);
        }

        $key = 'propertyware_discovery:'.$user->id.':'.hash('sha256', $user->email);

        if (! $request->session()->has($key)) {
            try {
                $this->syncAccounts->handle($user);
                $request->session()->put($key, true);
            } catch (PropertywareException) {
                $request->session()->put($key, true);
            }
        }

        return $next($request);
    }
}
