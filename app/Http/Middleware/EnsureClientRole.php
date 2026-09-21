<?php

namespace App\Http\Middleware;

use App\Enums\ClientRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientRole
{
    /**
     * The session key that remembers the portal the client last used.
     */
    public const SESSION_KEY = 'client_role';

    /**
     * The session key holding the account the client is viewing, per portal.
     */
    public const ACCOUNT_SESSION_KEY = 'client_account';

    /**
     * Only allow users holding the given client role into that portal.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $clientRole = ClientRole::from($role);

        abort_unless($request->user()?->hasClientRole($clientRole), 403);

        $accounts = $request->user()->clientAccountsFor($clientRole);
        $selectedAccountId = (int) $request->session()->get(self::ACCOUNT_SESSION_KEY.':'.$role);

        if ($accounts->count() > 1 && ! $accounts->contains('id', $selectedAccountId)) {
            return to_route('accounts.index');
        }

        $request->session()->put(self::SESSION_KEY, $clientRole->value);

        return $next($request);
    }
}
