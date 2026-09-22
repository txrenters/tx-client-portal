<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Jobs\ImportOwnerLedgers;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Property;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * What an owner has been billed, by portfolio.
 *
 * Bills, vendors and the chart of accounts all come from the REST API. This is
 * the expense side: what the owner has been billed, split per portfolio.
 *
 * A bill can be split across many portfolios, so an owner is only ever shown
 * the splits belonging to portfolios they own, never the bill's full amount.
 */
class Financials
{
    /** How far back a single bill read covers, in months. */
    private const MONTHS = 12;

    /** Bills on one page of the list; the totals still count every one. */
    private const PER_PAGE = 10;

    /**
     * Bills are read one portfolio at a time, so an owner with dozens of them
     * picks one first rather than waiting on dozens of round trips.
     */
    public const MAX_PORTFOLIOS = 12;

    public function __construct(
        private PropertywareClient $propertyware,
        private PropertywareDirectory $directory,
        private LeaseLedgerStore $ledgers,
    ) {}

    /**
     * The window a set of filters covers, as [from, to].
     *
     * @param  array{from?: ?string, to?: ?string}  $filters
     * @return array{0: string, 1: string}
     */
    public function period(array $filters = []): array
    {
        $zone = config('app.display_timezone');
        $now = CarbonImmutable::now($zone);

        // The month you are in, as Propertyware's own owner portal opens.
        $from = $filters['from'] ?? $now->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $now->endOfMonth()->toDateString();

        return [$from, $to];
    }

    /**
     * The owner's expenses for the period, by portfolio and as a list of bills.
     *
     * @param  array{portfolios?: array<int, int|string>|null, portfolio?: int|string|null, from?: ?string, to?: ?string}  $filters
     * @return array{importing: bool, portfolios: array<int, array<string, mixed>>, bills: array<int, array<string, mixed>>, results: array<string, mixed>, totals: array<string, float|int>, from: string, to: string}|null
     *
     * @throws PropertywareException
     */
    public function forOwner(ClientScope $scope, array $filters = [], int $page = 1, ?string $path = null): ?array
    {
        if ($scope->role !== ClientRole::Owner || $scope->portfolioIds === []) {
            return null;
        }

        [$from, $to] = $this->period($filters);
        $portfolioIds = $this->chosenPortfolios($scope, $filters);

        $names = collect($this->directory->portfoliosFor($scope))->pluck('name', 'id')->all();

        $buildingIds = $this->chosenProperties($scope, $filters);
        // A large owner reads from what is held while the import fills the rest.
        $readNow = count($portfolioIds) <= self::MAX_PORTFOLIOS;

        $status = (string) ($filters['status'] ?? 'all');

        // A bill reaches the owner's statement on the day it is paid, which is
        // how Propertyware's own ledger dates it; one still unpaid is shown on
        // its bill date so it isn't hidden until someone pays it.
        //
        // Narrowed per portfolio rather than after merging them all. A single
        // portfolio's bills are a multi-megabyte cache entry that unserializes
        // into several times that in arrays, and one read covers thirteen
        // months while the page usually shows one — so holding every portfolio
        // whole, to then drop most of it, is what exhausted the memory limit.
        $inWindow = collect();

        foreach ($portfolioIds as $portfolioId) {
            $held = Cache::get($this->billsKey($portfolioId));

            if ($held === null && $readNow) {
                $held = $this->readBills($portfolioId);
            }

            foreach ($held ?? [] as $bill) {
                if ($this->within($this->postedOn($bill), $from, $to)) {
                    $inWindow->push($bill);
                }
            }

            unset($held);
        }

        $inWindow = $inWindow->values();

        // Only the vendors these bills name, not every vendor in thirteen months.
        $vendors = $this->vendorNames($inWindow->all());

        // The list names the building the way the owner portal does, so the
        // addresses of whatever these bills touch are read in one go.
        $addresses = $this->directory->addressesForBuildings(
            $inWindow
                ->flatMap(fn (array $bill): array => collect(is_array($bill['billSplits'] ?? null) ? $bill['billSplits'] : [])
                    ->filter(fn (array $split): bool => in_array((int) ($split['portfolioID'] ?? 0), $portfolioIds, true))
                    ->pluck('buildingID')
                    ->all())
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );

        $rows = $inWindow
            // One bill can carry a hundred splits; only this owner's count.
            ->map(fn (array $bill): array => $this->present($bill, $portfolioIds, $names, $vendors, $buildingIds, $addresses))
            ->filter(fn (array $bill): bool => $bill['amount'] > 0)
            ->filter(fn (array $bill): bool => match ($status) {
                'paid' => $bill['status'] === 'paid',
                'unpaid' => $bill['status'] !== 'paid',
                default => true,
            })
            ->sortByDesc('date')
            ->values();

        $portfolios = collect($portfolioIds)
            ->map(fn (int $portfolioId): array => [
                'id' => $portfolioId,
                'name' => $names[$portfolioId] ?? 'Portfolio '.$portfolioId,
                'expenses' => round($rows->sum(fn (array $bill): float => $bill['byPortfolio'][$portfolioId] ?? 0), 2),
                'paid' => round($rows->sum(fn (array $bill): float => $bill['paidByPortfolio'][$portfolioId] ?? 0), 2),
                'outstanding' => round($rows->sum(fn (array $bill): float => ($bill['byPortfolio'][$portfolioId] ?? 0)
                    - ($bill['paidByPortfolio'][$portfolioId] ?? 0)), 2),
                'bills' => $rows->filter(fn (array $bill): bool => isset($bill['byPortfolio'][$portfolioId]))->count(),
            ])
            ->sortByDesc('expenses')
            ->values()
            ->all();

        // A month of a large portfolio runs to hundreds of bills, so the list
        // is paged while the totals above it still count every one.
        $listed = $rows->map(fn (array $bill): array => collect($bill)->except(['byPortfolio', 'paidByPortfolio'])->all());
        $page = max($page, 1);
        $paginator = new LengthAwarePaginator(
            $listed->forPage($page, self::PER_PAGE)->values()->all(),
            $listed->count(),
            self::PER_PAGE,
            $page,
            ['path' => $path ?? '/', 'query' => array_filter([
                'portfolios' => $filters['portfolios'] ?? null,
                'properties' => $filters['properties'] ?? null,
                'status' => $status === 'all' ? null : $status,
                'range' => $filters['range'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ], filled(...))],
        );

        return [
            // True while bills for some of these portfolios are still to come.
            'importing' => ! $readNow && ! $this->hasBills($portfolioIds),
            'portfolios' => $portfolios,
            'bills' => $paginator->items(),
            'results' => $paginator->toArray(),
            'totals' => [
                'expenses' => round($rows->sum('amount'), 2),
                'paid' => round($rows->sum('paid'), 2),
                'outstanding' => round($rows->sum('amount') - $rows->sum('paid'), 2),
                'bills' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * What each property brought in over the period: rent billed on its leases,
     * and the money actually received against them.
     *
     * Propertyware's bulk accounting calls answer nothing for our login, but a
     * lease's own ledger does, and a building's leases are listable — so this
     * rolls the ledgers up per property. Every lease that overlapped the window
     * counts, so a mid-year turnover shows both tenancies.
     *
     * @param  array{portfolios?: array<int, int|string>|null, portfolio?: int|string|null, from?: ?string, to?: ?string}  $filters
     * @return array{importing: bool, properties: array<int, array<string, mixed>>, totals: array<string, float|int>, from: string, to: string}|null
     *
     * @throws PropertywareException
     */
    public function revenueByProperty(ClientScope $scope, array $filters = []): ?array
    {
        if ($scope->role !== ClientRole::Owner || $scope->buildingIds === []) {
            return null;
        }

        [$from, $to] = $this->period($filters);
        $chosen = $this->chosenPortfolios($scope, $filters);
        $narrowed = $chosen !== $scope->portfolioIds;
        $buildingIds = $this->chosenProperties($scope, $filters);
        $properties = collect($this->directory->propertiesFor($scope))
            // A building can come back once per portfolio it is read through.
            ->unique(fn (Property $property): int => $property->id)
            ->when($narrowed, fn ($all) => $all->filter(fn (Property $property): bool => $this->inPortfolios($property->id, $chosen)))
            ->when($buildingIds !== [], fn ($all) => $all->filter(
                fn (Property $property): bool => in_array($property->id, $buildingIds, true),
            ))
            ->values();

        $rows = $properties
            ->map(fn (Property $property): array => [
                'id' => $property->id,
                'name' => $property->name ?: $property->address,
                // So a portfolio's row can add its properties up.
                'portfolioId' => (int) ($this->directory->findBuilding($property->id)['portfolioID'] ?? 0),
                ...$this->leaseTotals($property->id, $from, $to),
            ])
            ->sortByDesc('received')
            ->values();

        return [
            // True while the background import is still reading their leases.
            'importing' => ImportOwnerLedgers::isRunning($scope->contactId),
            'properties' => $rows->all(),
            'totals' => [
                'billed' => round($rows->sum('billed'), 2),
                'received' => round($rows->sum('received'), 2),
                'properties' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Rent billed and money received on a building, across every lease that
     * ran during the period.
     *
     * @return array{billed: float, received: float, leases: int}
     *
     * @throws PropertywareException
     */
    private function leaseTotals(int $buildingId, string $from, string $to): array
    {
        $leases = collect($this->propertyware->list('leases', ['buildingID' => $buildingId], 100, 0))
            ->filter(fn (array $lease): bool => $this->overlaps($lease, $from, $to));

        $billed = 0.0;
        $received = 0.0;

        foreach ($leases as $lease) {
            $entries = $this->ledgers->entries((int) $lease['id'], $from, $to);

            $billed += (float) $entries->where('kind', 'charge')->sum('amount')
                - (float) $entries->where('kind', 'credit')->sum('amount');
            $received += (float) $entries->where('kind', 'payment')->sum('amount')
                - (float) $entries->where('kind', 'return')->sum('amount');
        }

        return ['billed' => round($billed, 2), 'received' => round($received, 2), 'leases' => $leases->count()];
    }

    /**
     * Whether a lease ran at any point during the period.
     *
     * @param  array<string, mixed>  $lease
     */
    private function overlaps(array $lease, string $from, string $to): bool
    {
        $start = substr((string) ($lease['startDate'] ?? ''), 0, 10);
        $end = substr((string) ($lease['moveOutDate'] ?? $lease['endDate'] ?? ''), 0, 10);

        return ($start === '' || $start <= $to) && ($end === '' || $end >= $from);
    }

    /**
     * The portfolios the owner asked for, or all of theirs. Anything that isn't
     * theirs is dropped rather than refused, so a stale link still works.
     *
     * @param  array{portfolios?: array<int, int|string>|null, portfolio?: int|string|null}  $filters
     * @return array<int, int>
     */
    private function chosenPortfolios(ClientScope $scope, array $filters): array
    {
        $asked = collect($filters['portfolios'] ?? [])
            ->when(filled($filters['portfolio'] ?? null), fn ($all) => $all->push($filters['portfolio']))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $scope->portfolioIds, true))
            ->unique()
            ->values()
            ->all();

        return $asked === [] ? $scope->portfolioIds : $asked;
    }

    /**
     * The properties the owner ticked, or none when they want them all.
     *
     * @param  array{properties?: array<int, int|string>|null}  $filters
     * @return array<int, int>
     */
    private function chosenProperties(ClientScope $scope, array $filters): array
    {
        return collect($filters['properties'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $scope->allowsBuilding($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $portfolioIds
     */
    private function inPortfolios(int $buildingId, array $portfolioIds): bool
    {
        $building = $this->directory->findBuilding($buildingId);

        return in_array((int) ($building['portfolioID'] ?? 0), $portfolioIds, true);
    }

    /**
     * One bill as the owner sees it: their share of it, and who it was from.
     *
     * @param  array<string, mixed>  $bill
     * @param  array<int, int>  $portfolioIds
     * @param  array<int, string>  $names
     * @param  array<int, string>  $vendors
     * @param  array<int, int>  $buildingIds
     * @param  array<int, string>  $addresses
     * @return array<string, mixed>
     */
    private function present(array $bill, array $portfolioIds, array $names, array $vendors, array $buildingIds = [], array $addresses = []): array
    {
        $accounts = $this->accounts();

        $splits = collect(is_array($bill['billSplits'] ?? null) ? $bill['billSplits'] : [])
            ->filter(fn (array $split): bool => in_array((int) ($split['portfolioID'] ?? 0), $portfolioIds, true))
            // A split posted to an income account is our own fee recovered from
            // the tenant, not the owner's cost, so it stays off their expenses —
            // this is what Propertyware's owner ledger counts too.
            ->filter(fn (array $split): bool => $this->isExpense($split, $accounts))
            // A chosen property counts only its own splits; a bill split at
            // portfolio level names no building, so it is left out.
            ->when($buildingIds !== [], fn ($all) => $all->filter(
                fn (array $split): bool => in_array((int) ($split['buildingID'] ?? 0), $buildingIds, true),
            ));

        $byPortfolio = $splits
            ->groupBy(fn (array $split): int => (int) $split['portfolioID'])
            ->map(fn ($group): float => round($group->sum(fn (array $split): float => (float) ($split['amount'] ?? 0)), 2))
            ->all();

        // What has been settled, per portfolio. The billed figure alone cannot
        // answer "how much of this is still owed" for one portfolio out of many.
        $paidByPortfolio = $splits
            ->groupBy(fn (array $split): int => (int) $split['portfolioID'])
            ->map(fn ($group): float => round($group->sum(fn (array $split): float => (float) ($split['amountPaid'] ?? 0)), 2))
            ->all();

        $amount = round($splits->sum(fn (array $split): float => (float) ($split['amount'] ?? 0)), 2);
        $paid = round($splits->sum(fn (array $split): float => (float) ($split['amountPaid'] ?? 0)), 2);

        return [
            'id' => (string) ($bill['id'] ?? ''),
            // The bill number staff and the owner portal both quote.
            'number' => filled($bill['billNumber'] ?? null) ? (string) $bill['billNumber'] : null,
            'date' => $this->postedOn($bill),
            'billDate' => substr((string) ($bill['billDate'] ?? ''), 0, 10),
            'dueDate' => filled($bill['dueDate'] ?? null) ? substr((string) $bill['dueDate'], 0, 10) : null,
            'paymentDate' => filled($bill['paymentDate'] ?? null) ? substr((string) $bill['paymentDate'], 0, 10) : null,
            'vendor' => $vendors[(int) ($bill['vendorID'] ?? 0)] ?? 'Vendor',
            'description' => $this->withoutAccountNumbers($bill['comments'] ?? null),
            // What it was spent on, from the chart of accounts: "Management
            // Fees", "HVAC Repair" — Propertyware's own wording.
            'accounts' => $splits
                ->map(fn (array $split): string => $accounts[(int) ($split['glAccountID'] ?? 0)]['name'] ?? '')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'amount' => $amount,
            'paid' => $paid,
            'status' => match (true) {
                $paid >= $amount && $amount > 0 => 'paid',
                $paid > 0 => 'part-paid',
                default => 'unpaid',
            },
            'workOrderId' => filled($bill['workOrderID'] ?? null) ? (string) $bill['workOrderID'] : null,
            // The buildings and unit the owner portal names on the bill. One
            // bill can be split across a whole portfolio, so they stay a list
            // and the page shows the first with a count of the rest.
            'buildings' => $splits
                ->map(fn (array $split): string => $addresses[(int) ($split['buildingID'] ?? 0)]
                    ?? trim((string) ($split['buildingAbbr'] ?? ''), " \t,"))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'unit' => $splits
                ->map(fn (array $split): string => trim((string) ($split['unitAbbr'] ?? ''), " \t,"))
                ->filter()
                ->unique()
                ->implode(', ') ?: null,
            'portfolios' => collect($byPortfolio)->keys()->map(fn (int $id): string => $names[$id] ?? 'Portfolio '.$id)->values()->all(),
            'byPortfolio' => $byPortfolio,
            'paidByPortfolio' => $paidByPortfolio,
        ];
    }

    /**
     * Read one portfolio's bills and keep them, so a later visit — or another
     * portfolio's read — never asks Propertyware for them again.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    public function readBills(int $portfolioId): array
    {
        // One read covers every window the page offers, and the page filters it
        // by bill date; anything older than this is outside what we show.
        $since = CarbonImmutable::now(config('app.display_timezone'))
            ->subMonths(self::MONTHS + 1)
            ->format('Y-m-d\TH:i:s\Z');

        // Propertyware's bill records carry markup, expense recoveries and a
        // dozen audit fields; a portfolio's worth of them exhausted the
        // worker's memory. Only what the page shows is kept.
        $bills = collect($this->propertyware->listAllForFilters('bills', 'portfolioID', [$portfolioId], ['lastModifiedDateTimeStart' => $since]))
            ->map(fn (array $bill): array => [
                'id' => $bill['id'] ?? null,
                'billNumber' => $bill['billNumber'] ?? null,
                'billDate' => $bill['billDate'] ?? null,
                'dueDate' => $bill['dueDate'] ?? null,
                'paymentDate' => $bill['paymentDate'] ?? null,
                'vendorID' => $bill['vendorID'] ?? null,
                'comments' => $bill['comments'] ?? null,
                'workOrderID' => $bill['workOrderID'] ?? null,
                'billSplits' => collect(is_array($bill['billSplits'] ?? null) ? $bill['billSplits'] : [])
                    ->map(fn (array $split): array => [
                        'portfolioID' => $split['portfolioID'] ?? null,
                        'buildingID' => $split['buildingID'] ?? null,
                        'buildingAbbr' => $split['buildingAbbr'] ?? null,
                        'unitAbbr' => $split['unitAbbr'] ?? null,
                        'glAccountID' => $split['glAccountID'] ?? null,
                        'amount' => $split['amount'] ?? 0,
                        'amountPaid' => $split['amountPaid'] ?? 0,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        Cache::put($this->billsKey($portfolioId), $bills, (int) config('services.propertyware.cache_seconds'));

        return $bills;
    }

    /**
     * Whether every one of these portfolios' bills is already held.
     *
     * @param  array<int, int>  $portfolioIds
     */
    public function hasBills(array $portfolioIds): bool
    {
        foreach ($portfolioIds as $portfolioId) {
            if (! Cache::has($this->billsKey($portfolioId))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The day a bill reaches the owner's statement: the day it was paid, or
     * its bill date while it is still outstanding.
     *
     * @param  array<string, mixed>  $bill
     */
    private function postedOn(array $bill): string
    {
        $paid = substr((string) ($bill['paymentDate'] ?? ''), 0, 10);

        return $paid !== '' ? $paid : substr((string) ($bill['billDate'] ?? ''), 0, 10);
    }

    /**
     * Whether a bill split is an owner expense.
     *
     * Without the chart of accounts every split counts, which is what this did
     * before it could read them: better a total that is a little high than a
     * page that suddenly shows nothing.
     *
     * @param  array<string, mixed>  $split
     * @param  array<int, array{name: string, type: string}>  $accounts
     */
    private function isExpense(array $split, array $accounts): bool
    {
        $type = $accounts[(int) ($split['glAccountID'] ?? 0)]['type'] ?? null;

        return $type === null || str_contains($type, 'Expense');
    }

    /**
     * Propertyware's chart of accounts, read once a day.
     *
     * @return array<int, array{name: string, type: string}>
     */
    private function accounts(): array
    {
        return Cache::remember('propertyware:gl-accounts', now()->addDay(), function (): array {
            try {
                return collect($this->propertyware->listAll('accounting/glaccounts', [], 2000))
                    ->filter(fn (array $account): bool => filled($account['id'] ?? null))
                    ->mapWithKeys(fn (array $account): array => [(int) $account['id'] => [
                        'name' => (string) ($account['name'] ?? ''),
                        'type' => (string) ($account['accountType'] ?? ''),
                    ]])
                    ->all();
            } catch (PropertywareException) {
                return [];
            }
        });
    }

    private function billsKey(int $portfolioId): string
    {
        return "propertyware:bills:v2:{$portfolioId}";
    }

    /**
     * A vendor's note without the utility account numbers it often carries.
     *
     * "NEC Co-Op Energy … Account#1008901006901232670117; Billing period …"
     * tells the owner which bill it was without publishing the account itself.
     */
    private function withoutAccountNumbers(mixed $comments): ?string
    {
        if (blank($comments)) {
            return null;
        }

        // "Account#1008…", "Acct No. 5551234", "Account#ELB 90419-419132400":
        // the label, an optional short prefix, and the run of digits it names.
        $cleaned = preg_replace(
            '/\b(?:account|acct|acc)\.?\s*(?:no\.?|number|#)?\s*[:#]?\s*(?:[A-Za-z]{1,6}\s+)?[A-Za-z0-9-]*\d{4,}[A-Za-z0-9-]*/i',
            '',
            (string) $comments,
        ) ?? (string) $comments;

        // Tidy what the removal leaves behind: doubled separators and spaces.
        $cleaned = preg_replace('/\s*([;,])\s*(?=[;,])/', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, ' ;,-');

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * The vendors behind a set of bills, by id.
     *
     * @param  array<int, array<string, mixed>>  $bills
     * @return array<int, string>
     *
     * @throws PropertywareException
     */
    private function vendorNames(array $bills): array
    {
        $ids = collect($bills)->pluck('vendorID')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $cacheKey = 'propertyware:vendors:'.md5($ids->sort()->implode(','));

        return Cache::remember($cacheKey, (int) config('services.propertyware.cache_seconds'), fn (): array => collect($this->propertyware->getMany($ids->map(fn ($id): string => "vendors/{$id}")->all()))
            ->filter(fn ($vendor): bool => is_array($vendor) && filled($vendor['id'] ?? null))
            ->mapWithKeys(fn (array $vendor): array => [(int) $vendor['id'] => (string) ($vendor['name'] ?? 'Vendor')])
            ->all());
    }

    private function within(mixed $date, string $from, string $to): bool
    {
        $day = substr((string) $date, 0, 10);

        return $day !== '' && $day >= $from && $day <= $to;
    }
}
