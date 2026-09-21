<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
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

        $result = $financials->forOwner($directory->scopeFor($account), $validated, (int) ($validated['page'] ?? 1), $request->url());

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
                'from' => $result['from'],
                'to' => $result['to'],
            ],
        ]);
    }
}
