<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Client\SyncClientAccounts;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MeController extends Controller
{
    /**
     * Show the signed-in user and the Propertyware accounts they can open.
     *
     * Accounts matching the verified email are discovered here, as the web
     * portal does on page load, at most once every few minutes per user.
     */
    public function __invoke(Request $request, SyncClientAccounts $syncAccounts): UserResource
    {
        $user = $request->user();

        if (config('services.propertyware.discover_accounts') && $user->hasVerifiedEmail()) {
            $key = 'propertyware_discovery:api:'.$user->id.':'.hash('sha256', $user->email);

            if (Cache::add($key, true, now()->addMinutes(5))) {
                try {
                    $syncAccounts->handle($user);
                } catch (PropertywareException) {
                    // Known accounts still load; discovery retries after the cooldown.
                }
            }
        }

        return new UserResource($user->load('clientAccounts'));
    }
}
