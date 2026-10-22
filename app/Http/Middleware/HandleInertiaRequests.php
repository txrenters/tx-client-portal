<?php

namespace App\Http\Middleware;

use App\Enums\ClientRole;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\DashboardController;
use App\Models\ClientAccount;
use App\Services\Announcements\AnnouncementBoard;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
                // Only decides whether the admin link is shown; every admin
                // route checks the gate again for itself.
                'isAdmin' => $request->user() !== null && Gate::forUser($request->user())->allows('admin'),
                // Staff are on the staff side unless they picked one of their own client accounts.
                'portalSide' => $request->user() !== null
                    && Gate::forUser($request->user())->allows('admin')
                    && ! ($request->hasSession() && $request->session()->get(DashboardController::SIDE_SESSION_KEY) === DashboardController::CLIENT_SIDE)
                    ? DashboardController::STAFF_SIDE
                    : DashboardController::CLIENT_SIDE,
                'canManageStaff' => $request->user() !== null && Gate::forUser($request->user())->allows('manage-staff'),
                // Set only while an admin is signed in as someone else, so
                // every page can say so rather than looking like a normal visit.
                // No session on an error raised before the session started.
                'impersonating' => $request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY),
            ],
            'client' => fn (): ?array => $this->clientPortal($request),
            // Staff notices for the banner; none on the admin side.
            'announcements' => fn (): array => $request->user() === null || $request->is('admin', 'admin/*')
                ? []
                : app(AnnouncementBoard::class)->forUser($request->user()),
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
            // Propertyware's own portal for the role in view: owner or tenant.
            'propertywarePortalUrl' => $activeRole === null ? null : config("services.propertyware.portal_urls.{$activeRole->value}"),
            // The referral card always at the foot of an owner's sidebar.
            'referralReward' => $activeRole === ClientRole::Owner ? app(ReferralProgram::class)->reward() : null,
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
