<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Propertyware\OwnerStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show the owner's statement for one portfolio and one finished month.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, OwnerStatement $statements): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $statements->monthOrDefault($validated['month'] ?? null);
        $portfolio = isset($validated['portfolio']) ? (int) $validated['portfolio'] : null;

        return Inertia::render('owner/Statement', [
            'filters' => ['portfolio' => $portfolio, 'month' => $month],
            'months' => $statements->months(),
            'portfolios' => Inertia::defer(function () use ($request, $directory, $statements): array {
                try {
                    return $statements->portfolios($this->clientScope($request, ClientRole::Owner, $directory));
                } catch (PropertywareException) {
                    return [];
                }
            }, 'options'),
            'statement' => Inertia::defer(function () use ($request, $directory, $statements, $portfolio, $month): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    // Only the owner's own portfolios, so an id in the query can never reach anyone else's.
                    $portfolioId = in_array($portfolio, $scope->portfolioIds, strict: true) ? $portfolio : ($scope->portfolioIds[0] ?? null);

                    return $portfolioId === null ? null : $statements->for($scope, $portfolioId, $month);
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }
}
