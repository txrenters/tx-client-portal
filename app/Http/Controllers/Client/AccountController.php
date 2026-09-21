<?php

namespace App\Http\Controllers\Client;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureClientRole;
use App\Models\ClientAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $accounts = $request->user()->clientAccounts;

        if ($accounts->count() < 2) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/SelectAccount', [
            'accounts' => $accounts->map(fn (ClientAccount $account): array => [
                'id' => $account->id,
                'label' => $account->label ?? 'Account '.$account->id,
                'portal' => $account->role->portalLabel(),
                'reference' => $account->propertyware_id,
            ])->values()->all(),
        ]);
    }

    /**
     * Switch which Propertyware record the client is viewing.
     *
     * Only the client's own accounts can be selected; anything else is
     * indistinguishable from a record that does not exist.
     */
    public function update(Request $request, ClientAccount $clientAccount): RedirectResponse
    {
        abort_unless($clientAccount->user_id === $request->user()->id, 404);

        $role = $clientAccount->role;

        $request->session()->put(EnsureClientRole::SESSION_KEY, $role->value);

        $request->session()->put(
            EnsureClientRole::ACCOUNT_SESSION_KEY.':'.$role->value,
            $clientAccount->id,
        );

        return to_route($role === ClientRole::Owner ? 'owner.dashboard' : 'resident.dashboard');
    }
}
