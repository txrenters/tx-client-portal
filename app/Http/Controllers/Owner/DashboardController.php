<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Concerns\ShowsDashboardFeeds;
use App\Http\Controllers\Controller;
use App\Jobs\WarmOwnerLedgerBalance;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use App\Services\Referrals\ReferralProgram;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesClientScope;
    use ShowsDashboardFeeds;

    /**
     * Show the owner's portfolio home page.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance, WorkOrderApprovals $approvals, DocumentLibrary $documents, ClientConversations $conversations, Financials $financials, OwnerLedger $ledger, ReferralProgram $program): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);
        $onceKey = 'owner-dashboard-'.$account->id;

        return Inertia::render('owner/Dashboard', [
            // The referral banner, always shown: owners cannot hide it.
            'referralReward' => $program->reward(),
            // The figures Propertyware's own owner dashboard opens with: what
            // the portfolio carried in, earned, spent, and has left.
            'ledger' => Inertia::defer(function () use ($request, $directory, $financials, $ledger): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    $portfolioId = $scope->portfolioIds[0] ?? null;

                    if ($portfolioId === null) {
                        return null;
                    }

                    [$from, $to] = $financials->period([]);
                    WarmOwnerLedgerBalance::dispatch($portfolioId, $from);

                    $period = $ledger->forPortfolio($scope, $portfolioId, $from, $to, withOpeningBalance: true);

                    // What is still owed on bills is held back before the owner
                    // is paid, alongside the portfolio's minimum balance.
                    $outstanding = (float) ($financials->forOwner($scope)['totals']['outstanding'] ?? 0);

                    return [
                        ...$period,
                        // The months the balance walk already read, charted.
                        'months' => $ledger->monthly($scope, $portfolioId, 12),
                        'outstanding' => round($outstanding, 2),
                        'availableBalance' => round(
                            $period['endingBalance'] - $period['portfolioMinimum'] - $outstanding,
                            2,
                        ),
                    ];
                } catch (PropertywareException) {
                    return null;
                }
            }, 'feeds'),
            'portfolio' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    $properties = $directory->propertiesFor($scope);
                } catch (PropertywareException) {
                    return null;
                }

                return [
                    'portfolioCount' => count($scope->portfolioIds),
                    'propertyCount' => count($properties),
                    // Leased against vacant, as the portfolio summary's ring.
                    'occupancy' => $directory->occupancyFor($scope),
                    'properties' => collect($properties)->take(6)->map->toArray()->all(),
                ];
            })->once(as: $onceKey.'-portfolio', until: now()->addMinutes(5)),
            'maintenance' => Inertia::defer(function () use ($request, $directory, $maintenance): array {
                try {
                    return $maintenance->summary($this->clientScope($request, ClientRole::Owner, $directory));
                } catch (PropertywareException) {
                    return ['status' => 'error', 'counts' => [], 'recent' => []];
                }
            }, 'maintenance')->once(as: $onceKey.'-maintenance', until: now()->addMinute()),
            'approvals' => Inertia::defer(function () use ($request, $directory, $approvals, $account): ?array {
                try {
                    return $approvals->summaryFor($this->clientScope($request, ClientRole::Owner, $directory), $account);
                } catch (PropertywareException) {
                    return null;
                }
            }, 'maintenance'),
            'financials' => Inertia::defer(function () use ($request, $directory, $financials): ?array {
                try {
                    $result = $financials->forOwner($this->clientScope($request, ClientRole::Owner, $directory));

                    return $result === null ? null : $result['totals'];
                } catch (PropertywareException) {
                    return null;
                }
            }, 'financials')->once(as: $onceKey.'-financials', until: now()->addMinutes(5)),
            // The cards under the fold: the latest messages and documents, and
            // the two of them merged with work orders as one activity list.
            'messages' => Inertia::defer(fn (): ?array => $this->latestMessages($account, $request->user(), $conversations), 'feeds')
                ->once(as: $onceKey.'-messages', until: now()->addMinute()),
            'documents' => Inertia::defer(function () use ($request, $directory, $documents): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                } catch (PropertywareException) {
                    return null;
                }

                return $this->latestDocuments($scope, $documents);
            }, 'feeds')->once(as: $onceKey.'-documents', until: now()->addMinutes(5)),
            'activity' => Inertia::defer(function () use ($request, $directory, $maintenance, $documents, $conversations, $account): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                } catch (PropertywareException) {
                    return null;
                }

                return $this->recentActivity(ClientRole::Owner, $account, $scope, $request->user(), $maintenance, $documents, $conversations);
            }, 'feeds')->once(as: $onceKey.'-activity', until: now()->addMinute()),
        ]);
    }
}
