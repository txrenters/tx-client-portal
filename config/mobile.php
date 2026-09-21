<?php

/*
|--------------------------------------------------------------------------
| Mobile Apps
|--------------------------------------------------------------------------
|
| Identifies the iOS and Android apps allowed to open this domain's links
| (invitations, password resets, email confirmation) and to share its
| passwords and passkeys. Leave a platform empty until its app exists; its
| association file then answers 404.
|
*/

$list = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, '')))));

return [

    'ios' => [
        // "TEAMID.bundle.id", e.g. "ABCDE12345.com.texasrenters.portal"; comma-separated for several apps.
        'app_ids' => $list('MOBILE_IOS_APP_IDS'),
    ],

    'android' => [
        'package' => env('MOBILE_ANDROID_PACKAGE'),
        // SHA-256 signing certificate fingerprints ("AB:CD:..."), comma-separated for debug and release keys.
        'sha256_cert_fingerprints' => $list('MOBILE_ANDROID_SHA256_CERT_FINGERPRINTS'),
    ],

    // Links the apps open instead of the browser.
    'link_paths' => [
        '/invitations/*',
        '/reset-password/*',
        '/email/confirm/*',
    ],

];
