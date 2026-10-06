<?php

namespace App\Services\Propertyware;

use App\Enums\ClientRole;
use App\Models\LeaseLedgerEntry;
use App\Services\Propertyware\Data\ClientScope;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What a tenant has been charged and what they have paid.
 *
 * Propertyware keeps this in its SOAP ledger, which posts charges as positive
 * amounts and money received as negative ones. The portal shows both as plain
 * amounts with a side, so a tenant reads "Rent 1,400.00" and "Payment 1,400.00"
 * rather than having to work out what a minus sign means.
 *
 * The ledger also carries Propertyware's own bookkeeping: money the tenant paid
 * ahead sits in Prepayments and is moved onto each charge as it falls due.
 * Counting those movements as payments would bill the tenant's own money twice,
 * so they are shown for what they are and left out of the totals.
 */
class LeaseLedger
{
    /**
     * How far back the portal reads, in months. The whole tenancy is fetched
     * once and kept, so this only has to be longer than any lease we hold.
     */
    private const MONTHS = 120;

    /** Entries on a page of the transactions list. */
    public const PER_PAGE = 10;

    /** Months the dashboard chart covers, ending with this one. */
    public const CHART_MONTHS = 12;

    public function __construct(
        private LeaseLedgerSource $source,
        private LeaseLedgerStore $store,
    ) {}

    public function isAvailable(): bool
    {
        return $this->source->isConfigured();
    }

    /**
     * The tenant's ledger, newest first, one page at a time.
     *
     * A long tenancy runs to hundreds of entries, so the page holds ten of
     * them while the totals still count every one.
     *
     * @return array{syncing: bool, entries: array<int, array<string, mixed>>, results: array<string, mixed>, charged: float, paid: float, months: array<int, array{month: string, charged: float, paid: float}>, from: string, to: string}|null
     *
     * @throws PropertywareException
     */
    public function forTenant(ClientScope $scope, ?string $from = null, ?string $to = null, int $page = 1, ?string $path = null): ?array
    {
        $leaseId = $scope->role === ClientRole::Resident ? ($scope->leaseIds[0] ?? null) : null;

        if ($leaseId === null || ! $this->isAvailable()) {
            return null;
        }

        $zone = config('app.display_timezone');
        $to ??= CarbonImmutable::now($zone)->toDateString();
        $from ??= CarbonImmutable::parse($to, $zone)->subMonths(self::MONTHS)->toDateString();

        $syncing = $this->store->isEmpty($leaseId) && $this->store->isStale($leaseId, $from, $to);
        $entries = $this->store->entries($leaseId, $from, $to)
            ->map(fn (LeaseLedgerEntry $entry): array => [
                'id' => (string) $entry->id,
                'date' => $entry->occurred_on->toDateString(),
                'kind' => $entry->kind,
                'direction' => $entry->direction,
                'amount' => (float) $entry->amount,
                'description' => $entry->description,
                'detail' => $entry->detail,
                'reference' => $entry->reference,
            ])
            ->all();

        $page = max($page, 1);
        $paginator = new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($entries),
            self::PER_PAGE,
            $page,
            ['path' => $path ?? '/'],
        );

        return [
            // True while the first read of this tenancy is still running.
            'syncing' => $syncing,
            // The page on screen, and the whole run behind the totals.
            'entries' => $paginator->items(),
            'results' => $paginator->toArray(),
            // What was billed, less anything credited back, and only money
            // actually received: prepayment movements are the tenant's own
            // money being applied, so they count on neither side.
            'charged' => $this->charged($entries),
            // A returned payment takes its money back out again.
            'paid' => $this->paid($entries),
            // The same two totals month by month, for the dashboard chart.
            'months' => $this->months($entries, $to, $zone),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * What was billed, less anything credited back.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function charged(array $entries): float
    {
        return round(
            collect($entries)->where('kind', 'charge')->sum('amount')
            - collect($entries)->where('kind', 'credit')->sum('amount'),
            2,
        );
    }

    /**
     * Money received, less any payment that was returned.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function paid(array $entries): float
    {
        return round(
            collect($entries)->where('kind', 'payment')->sum('amount')
            - collect($entries)->where('kind', 'return')->sum('amount'),
            2,
        );
    }

    /**
     * Charged and paid for each of the last twelve months, oldest first,
     * including months where nothing moved so the chart keeps its spacing.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{month: string, charged: float, paid: float}>
     */
    private function months(array $entries, string $to, string $zone): array
    {
        $byMonth = collect($entries)->groupBy(fn (array $entry): string => substr($entry['date'], 0, 7));
        $end = CarbonImmutable::parse($to, $zone)->startOfMonth();

        return collect(range(self::CHART_MONTHS - 1, 0))
            ->map(function (int $step) use ($byMonth, $end): array {
                $month = $end->subMonths($step)->format('Y-m');
                $entries = $byMonth->get($month, collect())->all();

                return [
                    'month' => $month,
                    'charged' => $this->charged($entries),
                    'paid' => $this->paid($entries),
                ];
            })
            ->all();
    }
}
