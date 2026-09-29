<?php

namespace App\Http\Controllers\Concerns;

use Inertia\Inertia;

/**
 * The toast the next page shows, which is how the front end hears whether an
 * action worked (see resources/js/lib/flashToast.ts).
 */
trait FlashesToasts
{
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
