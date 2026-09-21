<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show an overview of everything the owner holds.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        return Inertia::render('owner/Portfolio', [
            'portfolio' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    $properties = $directory->propertiesFor($scope);

                    return [
                        'portfolios' => $directory->portfoliosFor($scope),
                        'properties' => collect($properties)->map(fn (Property $property): array => $property->toArray())->all(),
                        'occupancy' => $directory->occupancyFor($scope),
                    ];
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'owner-portfolio-'.$account->id, until: now()->addMinutes(5)),
        ]);
    }
}
