<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DetailsOnFileController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show what TexasRenters.com has on record for the client, read only.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory): Response
    {
        $account = $this->currentClientAccount($request);

        return Inertia::render('settings/DetailsOnFile', [
            'hasClientAccount' => $account !== null,
            // What Propertyware holds for the client, or null when it couldn't be read.
            'contactDetails' => $account === null ? null : Inertia::defer(function () use ($account, $directory): ?array {
                try {
                    return $directory->contactDetailsFor($directory->scopeFor($account));
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'contact-details-'.$account->id, until: now()->addMinutes(5)),
        ]);
    }
}
