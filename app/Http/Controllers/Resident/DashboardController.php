<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Concerns\ShowsDashboardFeeds;
use App\Http\Controllers\Controller;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesClientScope;
    use ShowsDashboardFeeds;

    /**
     * Show the tenant's home page.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents, ClientConversations $conversations, LeaseLedger $ledger): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Resident);
        abort_if($account === null, 403);
        $onceKey = 'resident-dashboard-'.$account->id;

        return Inertia::render('resident/Dashboard', [
            'property' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    $properties = $directory->propertiesFor($this->clientScope($request, ClientRole::Resident, $directory));
                } catch (PropertywareException) {
                    return null;
                }

                return ($properties[0] ?? null)?->toArray();
            })->once(as: $onceKey.'-property', until: now()->addMinutes(5)),
            'maintenance' => Inertia::defer(function () use ($request, $directory, $maintenance): array {
                try {
                    return $maintenance->summary($this->clientScope($request, ClientRole::Resident, $directory));
                } catch (PropertywareException) {
                    return ['status' => 'error', 'counts' => [], 'recent' => []];
                }
            }, 'maintenance')->once(as: $onceKey.'-maintenance', until: now()->addMinute()),
            'lease' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    return $directory->leaseFor($this->clientScope($request, ClientRole::Resident, $directory))?->toArray();
                } catch (PropertywareException) {
                    return null;
                }
            }, 'lease')->once(as: $onceKey.'-lease', until: now()->addMinutes(5)),
            'ledger' => Inertia::defer(function () use ($request, $directory, $ledger): ?array {
                try {
                    return $ledger->forTenant($this->clientScope($request, ClientRole::Resident, $directory));
                } catch (PropertywareException) {
                    return null;
                }
            }, 'ledger'),
            // The cards under the fold: the latest messages and documents, and
            // the two of them merged with work orders as one activity list.
            'messages' => Inertia::defer(fn (): ?array => $this->latestMessages($account, $request->user(), $conversations), 'feeds')
                ->once(as: $onceKey.'-messages', until: now()->addMinute()),
            'documents' => Inertia::defer(function () use ($request, $directory, $documents): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Resident, $directory);
                } catch (PropertywareException) {
                    return null;
                }

                return $this->latestDocuments($scope, $documents);
            }, 'feeds')->once(as: $onceKey.'-documents', until: now()->addMinutes(5)),
            'activity' => Inertia::defer(function () use ($request, $directory, $maintenance, $documents, $conversations, $account): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Resident, $directory);
                } catch (PropertywareException) {
                    return null;
                }

                return $this->recentActivity(ClientRole::Resident, $account, $scope, $request->user(), $maintenance, $documents, $conversations);
            }, 'feeds')->once(as: $onceKey.'-activity', until: now()->addMinute()),
        ]);
    }
}
