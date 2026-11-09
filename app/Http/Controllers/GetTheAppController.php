<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the landing page's QR code points: one code that sends an Android
 * phone to Google Play and everything else to the App Store.
 */
class GetTheAppController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $isAndroid = str_contains((string) $request->userAgent(), 'Android');

        return redirect()->away($isAndroid
            ? config('mobile.android.play_store_url')
            : config('mobile.ios.app_store_url'));
    }
}
