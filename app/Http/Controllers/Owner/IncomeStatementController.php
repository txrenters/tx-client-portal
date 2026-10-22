<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Propertyware\IncomeStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IncomeStatementController extends Controller
{
    use ResolvesClientScope;

    /**
     * Download the owner's income statement for one portfolio and up to
     * twelve months as a PDF, made for this request and never kept.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, IncomeStatement $statements): Response
    {
        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
        ]);
        [$from, $to] = $statements->period($validated);

        $scope = $this->clientScope($request, ClientRole::Owner, $directory);
        $portfolio = isset($validated['portfolio']) ? (int) $validated['portfolio'] : null;
        // Only the owner's own portfolios, so an id in the query can never reach anyone else's.
        $portfolioId = in_array($portfolio, $scope->portfolioIds, strict: true) ? $portfolio : ($scope->portfolioIds[0] ?? null);

        abort_if($portfolioId === null, 404);

        return $statements->download($statements->for($scope, $portfolioId, $from, $to));
    }
}
