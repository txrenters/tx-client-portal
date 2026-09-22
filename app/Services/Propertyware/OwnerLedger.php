<?php

namespace App\Services\Propertyware;

use App\Services\Propertyware\Data\ClientScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * An owner's ledger for a period, as Propertyware's own owner portal shows it.
 *
 * Propertyware's general ledger is double entry, so every transaction carries
 * both sides. What an owner recognises as their ledger is one side of it:
 *
 *   Income          credits to income accounts (rent, fees)
 *   Expense         debits to expense accounts (bills, management fees)
 *   Owner draw      debits to equity, the money paid out to them
 *   Account balance income less expenses less draws, running from inception
 *
 * Those four rules reproduce Propertyware's own figures exactly, which is how
 * they were arrived at rather than guessed.
 *
 * Two things this must get right. The ledger holds every portfolio in the
 * account, including TexasRenters.com's own books, so entries are filtered to
 * the portfolios the client actually owns and never merely requested. And the
 * API refuses a date range longer than 30 days, so a month is read in halves
 * and a longer history a month at a time.
 */
class OwnerLedger
{
    /** Months a page will read on the spot before giving up and warming up behind the visitor. */
    private const COLD_MONTHS_ALLOWED = 3;

    /** Accounts charted before the rest are added together. */
    private const TOP_ACCOUNTS = 5;

    /** Accounts named in a line's details before the rest are counted. */
    private const DETAILS_SHOWN = 2;

    /** Propertyware refuses a window longer than this. */
    private const MAX_WINDOW_DAYS = 30;

    /**
     * How long a settled month is kept. A month that closed long ago is only
     * ever reopened by a back-dated entry, which in practice lands within a
     * quarter, so recent history is re-read far sooner than old history. This
     * matters: a portfolio's balance runs from its first day, and the median
     * one here holds six years of books.
     */
    private const RECENT_MONTH_DAYS = 7;

    private const SETTLED_MONTH_DAYS = 120;

    /** Months treated as still liable to change. */
    private const RECENT_MONTHS = 3;

    /** How long the month in progress is kept before being read again. */
    private const OPEN_MONTH_MINUTES = 15;

    /** How far back a balance is accumulated when a portfolio has no start date. */
    private const MAX_HISTORY_YEARS = 8;

    public function __construct(private PropertywareClient $propertyware) {}

    /**
     * The ledger for one portfolio over one period.
     *
     * @param  array<int, int>  $buildingIds  Only these properties; all of them when empty.
     * @param  array<int, string>  $locations  Building id to the address an owner would recognise.
     * @return array{
     *     income: float, expense: float, managementFee: float,
     *     openingBalance: float, endingBalance: float, net: float,
     *     fromInception: bool, portfolioMinimum: float,
     *     incomeAccounts: array<int, array{name: string, amount: float}>,
     *     expenseAccounts: array<int, array{name: string, amount: float}>,
     *     entries: array<int, array<string, mixed>>, from: string, to: string
     * }
     *
     * @throws PropertywareException
     */
    public function forPortfolio(
        ClientScope $scope,
        int $portfolioId,
        string $from,
        string $to,
        bool $withOpeningBalance = false,
        array $buildingIds = [],
        array $locations = [],
    ): array {
        if (! in_array($portfolioId, $scope->portfolioIds, strict: true)) {
            throw new PropertywareException('That portfolio does not belong to this client.');
        }

        $accounts = $this->accounts();
        $transactions = $this->transactions($portfolioId, $from, $to);
        // Propertyware's Account Balance runs from the portfolio's first day,
        // which costs a request per month of history. Once those months are
        // cached it is instant, so the balance is used when it is ready and
        // the period's own movement is shown until then.
        // Narrowing to a property still carries the portfolio's balance in,
        // as Propertyware's own owner portal does: the ledger then reads as
        // the account balance moved by that property's transactions.
        $opening = $withOpeningBalance ? $this->openingBalance($portfolioId, $from) : null;
        $fromInception = $opening !== null;
        $opening ??= 0.0;

        $income = 0.0;
        $expense = 0.0;
        $entries = [];
        $byAccount = ['income' => [], 'expense' => []];
        $managementFee = $this->managementFee($transactions, $portfolioId, $accounts);

        foreach ($transactions as $transaction) {
            $this->tallyAccounts($transaction, $portfolioId, $accounts, $buildingIds, $byAccount);

            $entry = $this->present($transaction, $portfolioId, $accounts, $buildingIds, $locations);

            if ($entry === null) {
                continue;
            }

            $income += $entry['income'];
            $expense += $entry['expense'];
            $entries[] = $entry;
        }

        // Oldest first so the running balance reads forwards, then reversed for
        // the page, which shows the most recent movement at the top.
        usort($entries, fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $balance = $opening;

        foreach ($entries as $index => $entry) {
            $balance = round($balance + $entry['amount'], 2);
            $entries[$index]['balance'] = $balance;
            unset($entries[$index]['income'], $entries[$index]['expense'], $entries[$index]['managementFee']);
        }

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'managementFee' => round($managementFee, 2),
            'openingBalance' => round($opening, 2),
            'endingBalance' => round($balance, 2),
            'net' => round($balance - $opening, 2),
            'fromInception' => $fromInception,
            'entries' => array_reverse($entries),
            'incomeAccounts' => $this->topAccounts($byAccount['income']),
            'expenseAccounts' => $this->topAccounts($byAccount['expense']),
            'portfolioMinimum' => $this->portfolioMinimum($portfolioId),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Read and cache every month a portfolio's balance needs.
     *
     * Slow by nature, so it belongs in a queued job rather than a request.
     *
     * @throws PropertywareException
     */
    public function warmBalance(int $portfolioId, string $from): void
    {
        $start = $this->startOfHistory($portfolioId);
        $until = CarbonImmutable::parse($from)->subDay();
        $month = $start->startOfMonth();

        while ($month->lte($until)) {
            $this->monthTotals($portfolioId, $month, $until);
            $month = $month->addMonth();
        }
    }

    /**
     * One transaction as a ledger line, or null when none of it is this
     * portfolio's or it nets to nothing for the owner.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<int, array{name: string, type: string}>  $accounts
     * @param  array<int, int>  $buildingIds
     * @param  array<int, string>  $locations
     * @return array<string, mixed>|null
     */
    private function present(array $transaction, int $portfolioId, array $accounts, array $buildingIds = [], array $locations = []): ?array
    {
        $income = 0.0;
        $expense = 0.0;
        $draw = 0.0;
        $managementFee = 0.0;
        $details = [];
        $location = null;

        foreach ($transaction['glentries'] ?? [] as $entry) {
            if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                continue;
            }

            if ($buildingIds !== [] && ! in_array((int) ($entry['buildingID'] ?? 0), $buildingIds, strict: true)) {
                continue;
            }

            $account = $accounts[(int) ($entry['glaccountID'] ?? 0)] ?? null;
            $name = (string) ($account['name'] ?? '');
            $type = (string) ($account['type'] ?? '');
            $credit = (float) ($entry['credit'] ?? 0);
            $debit = (float) ($entry['debit'] ?? 0);

            // The other side of every entry is a bank or liability account,
            // which is the management company's own bookkeeping rather than
            // anything owed to the owner, so only these three count.
            $counts = true;

            if ($type === 'Income') {
                $income += $credit - $debit;
            } elseif (str_contains($type, 'Expense')) {
                $expense += $debit - $credit;
            } elseif ($type === 'Equity') {
                $draw += $debit - $credit;
            } else {
                $counts = false;
            }

            if (stripos($name, 'management fee') !== false) {
                $managementFee += $debit - $credit;
            }

            if ($counts && $name !== '') {
                $details[] = $this->detail($name, (string) ($entry['comment'] ?? ''));
            }

            $location ??= $this->location($entry, $locations);
        }

        if ($income === 0.0 && $expense === 0.0 && $draw === 0.0) {
            return null;
        }

        return [
            'id' => (int) ($transaction['id'] ?? 0),
            'date' => substr((string) ($transaction['txDate'] ?? ''), 0, 10),
            'reference' => filled($transaction['comment'] ?? null) ? (string) $transaction['comment'] : null,
            'location' => $location,
            'description' => (string) ($transaction['transactionType'] ?? ''),
            'details' => $this->summarise($details),
            'amount' => round($income - $expense - $draw, 2),
            'balance' => 0.0,
            'income' => $income,
            'expense' => $expense,
            'managementFee' => $managementFee,
        ];
    }

    /**
     * One line's description: the account, and the comment only when it says
     * something the account name does not.
     *
     * Propertyware repeats the account name in the comment on most entries
     * ("Owner Draw" / "Owner Draw"), so appending it blindly reads as a
     * stutter and buries the comments that do carry detail.
     */
    private function detail(string $name, string $comment): string
    {
        $comment = trim($comment);

        if ($comment === '' || mb_stripos($comment, $name) !== false || mb_stripos($name, $comment) !== false) {
            return $name;
        }

        return $name.' — '.$comment;
    }

    /**
     * The details of a transaction as one short line.
     *
     * A transaction can touch a dozen accounts; listing them all makes the
     * column unreadable, so it names the first few and counts the rest.
     *
     * @param  array<int, string>  $details
     */
    private function summarise(array $details): ?string
    {
        $unique = array_values(array_unique(array_filter($details)));

        if ($unique === []) {
            return null;
        }

        $shown = array_slice($unique, 0, self::DETAILS_SHOWN);
        $remaining = count($unique) - count($shown);

        return implode(', ', $shown).($remaining > 0 ? " +{$remaining} more" : '');
    }

    /**
     * What the portfolio was charged in management fees.
     *
     * The fee is charged to the portfolio rather than to a property, so
     * narrowing the ledger to one property does not reduce it — which is what
     * Propertyware's own owner portal shows.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     * @param  array<int, array{name: string, type: string}>  $accounts
     */
    private function managementFee(array $transactions, int $portfolioId, array $accounts): float
    {
        $fee = 0.0;

        foreach ($transactions as $transaction) {
            foreach ($transaction['glentries'] ?? [] as $entry) {
                if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                    continue;
                }

                $name = (string) ($accounts[(int) ($entry['glaccountID'] ?? 0)]['name'] ?? '');

                if (stripos($name, 'management fee') !== false) {
                    $fee += (float) ($entry['debit'] ?? 0) - (float) ($entry['credit'] ?? 0);
                }
            }
        }

        return round($fee, 2);
    }

    /**
     * Add a transaction's money to the running total for each account it
     * touched, so the dashboard can show where income and expense came from.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<int, array{name: string, type: string}>  $accounts
     * @param  array<int, int>  $buildingIds
     * @param  array{income: array<string, float>, expense: array<string, float>}  $byAccount
     */
    private function tallyAccounts(array $transaction, int $portfolioId, array $accounts, array $buildingIds, array &$byAccount): void
    {
        foreach ($transaction['glentries'] ?? [] as $entry) {
            if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                continue;
            }

            if ($buildingIds !== [] && ! in_array((int) ($entry['buildingID'] ?? 0), $buildingIds, strict: true)) {
                continue;
            }

            $account = $accounts[(int) ($entry['glaccountID'] ?? 0)] ?? null;
            $name = (string) ($account['name'] ?? '');
            $type = (string) ($account['type'] ?? '');
            $credit = (float) ($entry['credit'] ?? 0);
            $debit = (float) ($entry['debit'] ?? 0);

            if ($name === '') {
                continue;
            }

            if ($type === 'Income') {
                $byAccount['income'][$name] = round(($byAccount['income'][$name] ?? 0) + $credit - $debit, 2);
            } elseif (str_contains($type, 'Expense')) {
                $byAccount['expense'][$name] = round(($byAccount['expense'][$name] ?? 0) + $debit - $credit, 2);
            }
        }
    }

    /**
     * The biggest few accounts, largest first, with the rest added together.
     *
     * Propertyware shows the top five; a portfolio can touch thirty accounts
     * in a month and a chart of thirty slices says nothing.
     *
     * @param  array<string, float>  $totals
     * @return array<int, array{name: string, amount: float}>
     */
    private function topAccounts(array $totals): array
    {
        $totals = array_filter($totals, fn (float $amount): bool => $amount > 0);
        arsort($totals);

        $top = array_slice($totals, 0, self::TOP_ACCOUNTS, preserve_keys: true);
        $rest = round(array_sum($totals) - array_sum($top), 2);

        $accounts = [];

        foreach ($top as $name => $amount) {
            $accounts[] = ['name' => $name, 'amount' => $amount];
        }

        if ($rest > 0) {
            $accounts[] = ['name' => 'Everything else', 'amount' => $rest];
        }

        return $accounts;
    }

    /**
     * The balance Propertyware keeps back for the portfolio before paying the
     * owner, which their dashboard calls the portfolio minimum.
     */
    private function portfolioMinimum(int $portfolioId): float
    {
        try {
            return round((float) ($this->propertyware->get("portfolios/{$portfolioId}")['targetOperatingReserve'] ?? 0), 2);
        } catch (PropertywareException) {
            return 0.0;
        }
    }

    /**
     * Which property the money applied to.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $locations
     */
    private function location(array $entry, array $locations = []): ?string
    {
        $building = $entry['buildingID'] ?? null;

        if (blank($building)) {
            return null;
        }

        // The address the owner knows the property by, falling back to the id
        // when the property is not one of theirs to name.
        return $locations[(int) $building] ?? null;
    }

    /**
     * The balance carried into the period, accumulated from the portfolio's
     * first day so the running balance matches Propertyware's own.
     *
     * @throws PropertywareException
     */
    private function openingBalance(int $portfolioId, string $from): ?float
    {
        $start = $this->startOfHistory($portfolioId);
        $until = CarbonImmutable::parse($from)->subDay();

        if ($until->lt($start)) {
            return 0.0;
        }

        $balance = 0.0;
        $month = $start->startOfMonth();
        $missing = 0;

        while ($month->lte($until)) {
            // A cold portfolio means reading a decade a month at a time. Give
            // up as soon as that is clear rather than after doing it: the
            // caller shows the period's own movement and the warm-up job
            // fills the cache behind the visitor.
            if ($missing > self::COLD_MONTHS_ALLOWED) {
                return null;
            }

            $balance += $this->monthTotals($portfolioId, $month, $until, $missing)['net'];
            $month = $month->addMonth();
        }

        return $missing > self::COLD_MONTHS_ALLOWED ? null : round($balance, 2);
    }

    /**
     * One month's income, expense and net movement, cached.
     *
     * A month that has closed cannot change except by a back-dated entry, so
     * recent history is kept briefly and settled history for far longer. The
     * same entry serves the running balance and the twelve month chart, so
     * neither pays for the other.
     *
     * @return array{income: float, expense: float, net: float}
     *
     * @throws PropertywareException
     */
    private function monthTotals(int $portfolioId, CarbonImmutable $month, CarbonImmutable $until, int &$missing = 0): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->gt($until) ? $until : $month->endOfMonth();

        $startOfThisMonth = CarbonImmutable::now()->startOfMonth();
        $closed = $to->lt($startOfThisMonth);
        $recent = $to->gte($startOfThisMonth->subMonths(self::RECENT_MONTHS));

        $key = "propertyware:owner-ledger:v2:{$portfolioId}:{$from->toDateString()}:{$to->toDateString()}";
        $ttl = match (true) {
            ! $closed => now()->addMinutes(self::OPEN_MONTH_MINUTES),
            $recent => now()->addDays(self::RECENT_MONTH_DAYS),
            default => now()->addDays(self::SETTLED_MONTH_DAYS),
        };

        if (! Cache::has($key)) {
            $missing++;
        }

        /** @var array{income: float, expense: float, net: float} $totals */
        $totals = Cache::remember($key, $ttl, function () use ($portfolioId, $from, $to): array {
            $accounts = $this->accounts();
            $income = 0.0;
            $expense = 0.0;
            $draw = 0.0;

            foreach ($this->transactions($portfolioId, $from->toDateString(), $to->toDateString()) as $transaction) {
                foreach ($transaction['glentries'] ?? [] as $entry) {
                    if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                        continue;
                    }

                    $type = (string) ($accounts[(int) ($entry['glaccountID'] ?? 0)]['type'] ?? '');
                    $credit = (float) ($entry['credit'] ?? 0);
                    $debit = (float) ($entry['debit'] ?? 0);

                    if ($type === 'Income') {
                        $income += $credit - $debit;
                    } elseif (str_contains($type, 'Expense')) {
                        $expense += $debit - $credit;
                    } elseif ($type === 'Equity') {
                        $draw += $debit - $credit;
                    }
                }
            }

            return [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'net' => round($income - $expense - $draw, 2),
            ];
        });

        return $totals;
    }

    /**
     * What came in and went out each month, oldest first.
     *
     * @return array<int, array{month: string, income: float, expense: float}>
     *
     * @throws PropertywareException
     */
    public function monthly(ClientScope $scope, int $portfolioId, int $months = 12): array
    {
        if (! in_array($portfolioId, $scope->portfolioIds, strict: true)) {
            throw new PropertywareException('That portfolio does not belong to this client.');
        }

        $now = CarbonImmutable::now();
        $rows = [];

        for ($step = $months - 1; $step >= 0; $step--) {
            $month = $now->subMonths($step)->startOfMonth();
            $totals = $this->monthTotals($portfolioId, $month, $now->endOfMonth());

            $rows[] = [
                'month' => $month->format('Y-m'),
                'income' => $totals['income'],
                'expense' => $totals['expense'],
            ];
        }

        return $rows;
    }

    /**
     * When the portfolio's books start.
     *
     * A balance has to accumulate from the portfolio's first day, because
     * starting anywhere later leaves it wrong by whatever happened before.
     */
    private function startOfHistory(int $portfolioId): CarbonImmutable
    {
        $floor = CarbonImmutable::now()->subYears(self::MAX_HISTORY_YEARS);

        try {
            $created = $this->propertyware->get("portfolios/{$portfolioId}")['createdDateTime'] ?? null;
        } catch (PropertywareException) {
            return $floor;
        }

        if (blank($created)) {
            return $floor;
        }

        // The floor is a fallback for a portfolio with no start date, not a
        // limit on one that has it: clamping a portfolio opened before the
        // floor silently drops its earliest months and leaves every balance
        // after them wrong by a constant.
        return CarbonImmutable::parse((string) $created);
    }

    /**
     * Every transaction touching a portfolio in a window, read in chunks the
     * API will accept.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PropertywareException
     */
    private function transactions(int $portfolioId, string $from, string $to): array
    {
        $transactions = [];
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($start->lte($end)) {
            $chunkEnd = $start->addDays(self::MAX_WINDOW_DAYS - 1);
            $chunkEnd = $chunkEnd->gt($end) ? $end : $chunkEnd;

            $transactions = [...$transactions, ...$this->propertyware->listAll('accounting/generalledger', [
                'postDateStart' => $start->toDateString(),
                'postDateEnd' => $chunkEnd->toDateString(),
                'portfolioID' => $portfolioId,
            ], 5000)];

            $start = $chunkEnd->addDay();
        }

        return $transactions;
    }

    /**
     * Propertyware's chart of accounts, keyed by id.
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
                        'name' => trim((string) ($account['name'] ?? '')),
                        'type' => (string) ($account['accountType'] ?? ''),
                    ]])
                    ->all();
            } catch (PropertywareException) {
                return [];
            }
        });
    }
}
