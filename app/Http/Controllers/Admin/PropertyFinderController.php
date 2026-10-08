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
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/PropertyFinder', [
            'search' => $search,
            'maxResults' => PropertyFinder::MAX_RESULTS,
            // Deferred: a search reads Propertyware, which can take a while.
            // With nothing typed yet, the properties of the people on the portal.
            'found' => Inertia::defer(function () use ($finder, $search, $request): ?array {
                if ($search !== '' && mb_strlen($search) < 3) {
                    return null;
                }

                try {
                    return $search === ''
                        ? [...$finder->onPortal(max(1, $request->integer('page', 1))), 'mode' => 'portal', 'error' => null]
                        : [...$finder->search($search), 'mode' => 'search', 'error' => null];
                } catch (PropertywareException) {
                    return ['results' => [], 'total' => 0, 'mode' => $search === '' ? 'portal' : 'search', 'error' => 'Propertyware could not be reached. Try again in a moment.'];
                }
            }),
        ]);
    }
}
