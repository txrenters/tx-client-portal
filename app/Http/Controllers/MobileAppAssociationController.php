<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class MobileAppAssociationController extends Controller
{
    /**
     * Tell iOS which apps may open this domain's links and use its passkeys.
     *
     * @see https://developer.apple.com/documentation/xcode/supporting-associated-domains
     */
    public function apple(): JsonResponse
    {
        $appIds = config('mobile.ios.app_ids');

        abort_if($appIds === [], 404);

        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => $appIds,
                    'components' => array_map(fn (string $path): array => ['/' => $path], config('mobile.link_paths')),
                ]],
            ],
            'webcredentials' => ['apps' => $appIds],
        ]);
    }

    /**
     * Tell Android which app may open this domain's links and use its passkeys.
     *
     * @see https://developer.android.com/training/app-links/verify-android-applinks
     */
    public function android(): JsonResponse
    {
        $package = config('mobile.android.package');
        $fingerprints = config('mobile.android.sha256_cert_fingerprints');

        abort_if(blank($package) || $fingerprints === [], 404);

        return response()->json([[
            'relation' => [
                'delegate_permission/common.handle_all_urls',
                'delegate_permission/common.get_login_creds',
            ],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]]);
    }
}
