<?php

namespace App\PortalSso;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The portal calls this when someone is deactivated or signs out everywhere. Ends their
 * web sessions, including any visit where they are signed in as a client (impersonation),
 * whose session row belongs to the client. The phone apps' tokens are left alone.
 */
class BackchannelLogoutController extends Controller
{
    public function __invoke(Request $request, LogoutTokenVerifier $verifier): Response
    {
        try {
            $sub = $verifier->verify((string) $request->input('logout_token'));
        } catch (PortalAuthException $e) {
            Log::warning('Rejected portal logout request', ['error' => $e->getMessage(), 'ip' => $request->ip()]);

            return response('', 400)->header('Cache-Control', 'no-store');
        }

        // Every portal session of this person ends on its next request (EnsurePortalSession),
        // even one whose session row now carries a client's user id.
        PortalSignOuts::record($sub);

        if ($user = User::where('portal_subject', $sub)->first()) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

            Log::info('Signed out by the TR Staff Portal', ['user_id' => $user->id]);
        }

        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
