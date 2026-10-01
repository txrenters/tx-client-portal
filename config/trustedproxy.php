<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Behind a load balancer or proxy every visitor arrives from its address.
| TRUSTED_PROXIES names it (a comma list, or * on a platform that manages
| this), so the visitor's own address is what gets logged and blocked.
| Laravel's TrustProxies middleware reads this on every request. Left
| empty, nothing is trusted.
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
