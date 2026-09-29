<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PropertyFinder;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Look a property up by address and see who is on the portal for it.
 */
class PropertyFinderController extends Controller
{
    public function __invoke(Request $request, PropertyFinder $finder): Response
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/PropertyFinder', [
            'search' => $search,
            'maxResults' => PropertyFinder::MAX_RESULTS,
            // Deferred: a search reads Propertyware, which can take a while.
            'found' => Inertia::defer(function () use ($finder, $search): ?array {
                if (mb_strlen($search) < 3) {
                    return null;
                }

                try {
                    return [...$finder->search($search), 'error' => null];
                } catch (PropertywareException) {
                    return ['results' => [], 'total' => 0, 'error' => 'Propertyware could not be reached. Try again in a moment.'];
                }
            }),
        ]);
    }
}
