<?php

namespace App\Http\Middleware;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'client' => fn (): ?array => $this->clientPortal($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Get the portals the signed-in client can use and the one they're currently in.
     *
     * @return array{roles: array<int, string>, activeRole: ?string, accounts: array<int, array{id: int, label: string, reference: string, portal: string, active: bool}>}|null
     */
    private function clientPortal(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $preferredRole = ClientRole::tryFrom((string) $request->session()->get(EnsureClientRole::SESSION_KEY));

        $activeRole = $user->preferredClientRole($preferredRole);

        $accounts = $user->clientAccounts;

        $activeAccountId = (int) $request->session()->get(
            EnsureClientRole::ACCOUNT_SESSION_KEY.':'.$activeRole?->value,
            $accounts->firstWhere('role', $activeRole)?->id,
        );

        return [
            'roles' => $user->clientRoles()->map(fn (ClientRole $role): string => $role->value)->all(),
            'activeRole' => $activeRole?->value,
            'accounts' => $accounts->map(fn (ClientAccount $account): array => [
                'id' => $account->id,
                'label' => $account->label ?? 'Account '.$account->id,
                'reference' => $account->propertyware_id,
                'portal' => $account->role->portalLabel(),
                'active' => $account->role === $activeRole && $account->id === $activeAccountId,
            ])->all(),
        ];
    }
}
