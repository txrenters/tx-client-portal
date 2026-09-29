<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Jobs\ImportOwnerLedgers;
use App\Models\ClientAccount;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialController extends Controller
{
    /**
     * What an owner has been billed, by portfolio.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, ClientAccount $account, PropertywareDirectory $directory, Financials $financials): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $validated = $request->validate([
            'portfolios' => ['nullable', 'array'],
            'portfolios.*' => ['integer'],
            'properties' => ['nullable', 'array'],
            'properties.*' => ['integer'],
            'status' => ['nullable', 'in:all,paid,unpaid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $scope = $directory->scopeFor($account);

        // Opening the screen starts the import, the way the web portal does;
        // nothing here waits on it. Without this an owner holding more than
        // Financials::MAX_PORTFOLIOS portfolios is served from a cache nothing
        // ever fills, so every portfolio reads zero for good.
        ImportOwnerLedgers::dispatch($scope, ...$financials->period([]));

        $result = $financials->forOwner($scope, $validated, (int) ($validated['page'] ?? 1), $request->url());

        abort_if($result === null, 404);

        return response()->json([
            'data' => $result['bills'],
            'meta' => [
                'currentPage' => $result['results']['current_page'],
                'lastPage' => $result['results']['last_page'],
                'perPage' => $result['results']['per_page'],
                'total' => $result['results']['total'],
                'portfolios' => $result['portfolios'],
                'totals' => $result['totals'],
                // True while a large owner's bills are still being fetched, so
                // the app can say so rather than show every portfolio as zero.
                'importing' => $result['importing'],
                'from' => $result['from'],
                'to' => $result['to'],
            ],
        ]);
    }
}
