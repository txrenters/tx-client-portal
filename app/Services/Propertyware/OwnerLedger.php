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

    /**
     * The parent account of TexasRenters.com's own fees collected through the
     * owner's books, such as the Tenant Benefit Package. Each comes in with the
     * tenant's payment and goes straight back out to us on a bill, so it is
     * not the owner's income and nets to nothing on their balance; the
     * ledger leaves both sides out.
     */
    private const PASS_THROUGH_PARENT = 'Management Income';

    public function __construct(private PropertywareClient $propertyware) {}

    /**
     * The ledger for one portfolio over one period.
     *
     * Each entry carries `settled` (true once a tenant payment has been
     * deposited, false while it is on its way, null on any other line) and
     * `settledOn`, the day it was deposited.
     *
     * @param  array<int, int>  $buildingIds  Only these properties; all of them when empty.
     * @param  array<int, string>  $locations  Building id to the address an owner would recognise.
     * @return array{
     *     income: float, expense: float, managementFee: float, draws: float,
     *     openingBalance: float, endingBalance: float, net: float,
     *     fromInception: bool, portfolioMinimum: float,
     *     incomeAccounts: array<int, array{name: string, amount: float}>,
     *     expenseAccounts: array<int, array{name: string, amount: float}>,
     *     incomeByAccount: array<int, array{name: string, amount: float}>,
     *     expenseByAccount: array<int, array{name: string, amount: float}>,
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
        $draws = 0.0;
        $entries = [];
        $byAccount = ['income' => [], 'expense' => []];
        $managementFee = $this->managementFee($transactions, $portfolioId, $accounts);
        $deposits = $this->depositDates($portfolioId, $from, $to);

        foreach ($transactions as $transaction) {
            $this->tallyAccounts($transaction, $portfolioId, $accounts, $buildingIds, $byAccount);

            $entry = $this->present($transaction, $portfolioId, $accounts, $buildingIds, $locations);

            if ($entry === null) {
                continue;
            }

            $entry = [...$entry, ...$this->settlement($transaction, $entry, $deposits)];

            $income += $entry['income'];
            $expense += $entry['expense'];
            $draws += $entry['draw'];
            $entries[] = $entry;
        }

        // Oldest first so the running balance reads forwards, then reversed for
        // the page, which shows the most recent movement at the top.
        usort($entries, fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $balance = $opening;

        foreach ($entries as $index => $entry) {
            $balance = round($balance + $entry['amount'], 2);
            $entries[$index]['balance'] = $balance;
            unset($entries[$index]['income'], $entries[$index]['expense'], $entries[$index]['draw'], $entries[$index]['managementFee']);
        }

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'managementFee' => round($managementFee, 2),
            'draws' => round($draws, 2),
            'openingBalance' => round($opening, 2),
            'endingBalance' => round($balance, 2),
            'net' => round($balance - $opening, 2),
            'fromInception' => $fromInception,
            'entries' => array_reverse($entries),
            'incomeAccounts' => $this->topAccounts($byAccount['income']),
            'expenseAccounts' => $this->topAccounts($byAccount['expense']),
            'incomeByAccount' => $this->everyAccount($byAccount['income']),
            'expenseByAccount' => $this->everyAccount($byAccount['expense']),
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
     * The figures a portfolio's card leads with, as already read.
     *
     * Null when the portfolio has not been read yet. A list of ninety-four
     * cannot walk ninety-four general ledgers on one request, so these are
     * served from what has been cached, and a portfolio still waiting says so
     * rather than reporting zeroes it cannot stand behind.
     *
     * @return array{income: float, expense: float, managementFee: float}|null
     */
    public function heldSummary(int $portfolioId, string $from, string $to): ?array
    {
        $held = Cache::get($this->summaryKey($portfolioId, $from, $to));

        // Rebuilt rather than returned as found, so an entry left over from an
        // older shape reads as "not yet known" instead of leaking through.
        if (! is_array($held) || ! isset($held['income'], $held['expense'], $held['managementFee'])) {
            return null;
        }

        return [
            'income' => (float) $held['income'],
            'expense' => (float) $held['expense'],
            'managementFee' => (float) $held['managementFee'],
        ];
    }

    /**
     * Read a portfolio's figures for the window and hold on to them.
     *
     * The opening balance is deliberately left out: it costs a request per
     * month of history, and the list quotes what moved in the period rather
     * than a standing balance.
     *
     * @return array{income: float, expense: float, managementFee: float}
     *
     * @throws PropertywareException
     */
    public function warmSummary(ClientScope $scope, int $portfolioId, string $from, string $to): array
    {
        $book = $this->forPortfolio($scope, $portfolioId, $from, $to);
        $summary = [
            'income' => (float) $book['income'],
            'expense' => (float) $book['expense'],
            'managementFee' => (float) $book['managementFee'],
        ];

        Cache::put(
            $this->summaryKey($portfolioId, $from, $to),
            $summary,
            (int) config('services.propertyware.cache_seconds'),
        );

        return $summary;
    }

    private function summaryKey(int $portfolioId, string $from, string $to): string
    {
        return "owner-ledger:summary:{$portfolioId}:{$from}:{$to}";
    }

    /**
     * One transaction as a ledger line, or null when none of it is this
     * portfolio's or it nets to nothing for the owner.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<int, array{name: string, type: string, passThrough: bool}>  $accounts
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

            if ($account['passThrough'] ?? false) {
                continue;
            }

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
            'draw' => $draw,
            'managementFee' => $managementFee,
        ];
    }

    /**
     * Whether a tenant's payment has reached the bank yet.
     *
     * Propertyware records the day a payment is deposited; an eCheck lands
     * about five days after it is made, and can still be returned until then.
     * Null on anything that is not a tenant payment, or when the payment's
     * record could not be read.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $entry
     * @param  array<int, string|null>|null  $deposits  Deposit dates by payment id.
     * @return array{settled: bool|null, settledOn: string|null}
     */
    private function settlement(array $transaction, array $entry, ?array $deposits): array
    {
        $id = (int) ($transaction['id'] ?? 0);

        if (($transaction['transactionType'] ?? null) !== 'Tenant Payment' || $entry['amount'] <= 0 || $deposits === null || ! array_key_exists($id, $deposits)) {
            return ['settled' => null, 'settledOn' => null];
        }

        $depositedOn = $deposits[$id];
        $today = CarbonImmutable::now(config('app.display_timezone'))->toDateString();
        $settled = $depositedOn !== null && $depositedOn <= $today;

        return ['settled' => $settled, 'settledOn' => $settled ? $depositedOn : null];
    }

    /**
     * When each tenant payment posted in the period was deposited, by its id,
     * which is also the id of its line in the general ledger. Null when
     * Propertyware would not say, so lines show no status rather than a wrong one.
     *
     * @return array<int, string|null>|null
     */
    private function depositDates(int $portfolioId, string $from, string $to): ?array
    {
        try {
            $payments = $this->propertyware->listAll('leases/payments', [
                'portfolioID' => $portfolioId,
                'postDateStart' => $from,
                'postDateEnd' => $to,
            ], 5000);
        } catch (PropertywareException) {
            return null;
        }

        return collect($payments)
            ->filter(fn (array $payment): bool => filled($payment['id'] ?? null))
            ->mapWithKeys(fn (array $payment): array => [(int) $payment['id'] => filled($payment['depositDate'] ?? null)
                ? substr((string) $payment['depositDate'], 0, 10)
                : null])
            ->all();
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
     * @param  array<int, array{name: string, type: string, passThrough: bool}>  $accounts
     */
    private function managementFee(array $transactions, int $portfolioId, array $accounts): float
    {
        $fee = 0.0;

        foreach ($transactions as $transaction) {
            foreach ($transaction['glentries'] ?? [] as $entry) {
                if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                    continue;
                }

                $account = $accounts[(int) ($entry['glaccountID'] ?? 0)] ?? null;
                $name = (string) ($account['name'] ?? '');

                if (! ($account['passThrough'] ?? false) && stripos($name, 'management fee') !== false) {
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
     * @param  array<int, array{name: string, type: string, passThrough: bool}>  $accounts
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

            if ($name === '' || ($account['passThrough'] ?? false)) {
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
     * Every account that moved, largest first, for a statement that has to add up.
     *
     * @param  array<string, float>  $totals
     * @return array<int, array{name: string, amount: float}>
     */
    private function everyAccount(array $totals): array
    {
        $totals = array_filter($totals, fn (float $amount): bool => $amount !== 0.0);
        arsort($totals);

        return array_map(
            fn (string $name, float $amount): array => ['name' => $name, 'amount' => $amount],
            array_keys($totals),
            array_values($totals),
        );
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

        $key = "propertyware:owner-ledger:v2:{$portfolioId}:{$from->toDateString()}:{$to->toDateString()}";
        $ttl = $this->monthTtl($to);

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

                    $account = $accounts[(int) ($entry['glaccountID'] ?? 0)] ?? null;

                    if ($account['passThrough'] ?? false) {
                        continue;
                    }

                    $type = (string) ($account['type'] ?? '');
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
     * Every income and expense account the portfolio touched in each month
     * from one month to another, as Propertyware's Published Income Statement
     * reads it, except our own Management Income accounts: owners are never
     * shown what TexasRenters.com earns. Months after this one are left empty. An account that moved and came back to nothing
     * still appears, as it does there.
     *
     * @return array{
     *     months: array<string, array<int, float>>,
     *     accounts: array<int, array{name: string, type: string, number: string, parent: string, passThrough: bool}>
     * }
     *
     * @throws PropertywareException
     */
    public function accountsByMonth(ClientScope $scope, int $portfolioId, CarbonImmutable $first, CarbonImmutable $last): array
    {
        if (! in_array($portfolioId, $scope->portfolioIds, strict: true)) {
            throw new PropertywareException('That portfolio does not belong to this client.');
        }

        $accounts = $this->accounts();
        $now = CarbonImmutable::now();
        $months = [];

        for ($month = $first->startOfMonth(); $month->lte($last); $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = $month->gt($now)
                ? []
                : $this->monthAccounts($portfolioId, $month, $accounts);
        }

        $touched = array_unique(array_merge(...array_map(array_keys(...), array_values($months))));

        return [
            'months' => $months,
            'accounts' => array_intersect_key($accounts, array_flip($touched)),
        ];
    }

    /**
     * One month's movement on each income and expense account, cached like
     * the month totals: income as credits less debits, expense the other way.
     *
     * @param  array<int, array{name: string, type: string, number: string, parent: string, passThrough: bool}>  $accounts
     * @return array<int, float>
     *
     * @throws PropertywareException
     */
    private function monthAccounts(int $portfolioId, CarbonImmutable $month, array $accounts): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->gt(CarbonImmutable::now()) ? CarbonImmutable::now()->startOfDay() : $month->endOfMonth();

        return Cache::remember(
            "propertyware:owner-ledger:accounts:v2:{$portfolioId}:{$from->toDateString()}:{$to->toDateString()}",
            $this->monthTtl($to),
            function () use ($portfolioId, $from, $to, $accounts): array {
                $totals = [];

                foreach ($this->transactions($portfolioId, $from->toDateString(), $to->toDateString()) as $transaction) {
                    foreach ($transaction['glentries'] ?? [] as $entry) {
                        if ((int) ($entry['portfolioID'] ?? 0) !== $portfolioId) {
                            continue;
                        }

                        $id = (int) ($entry['glaccountID'] ?? 0);
                        $type = (string) ($accounts[$id]['type'] ?? '');

                        // Our own income is never shown to an owner.
                        if ($accounts[$id]['passThrough'] ?? false) {
                            continue;
                        }
                        $credit = (float) ($entry['credit'] ?? 0);
                        $debit = (float) ($entry['debit'] ?? 0);

                        if ($type === 'Income') {
                            $totals[$id] = round(($totals[$id] ?? 0) + $credit - $debit, 2);
                        } elseif (str_contains($type, 'Expense')) {
                            $totals[$id] = round(($totals[$id] ?? 0) + $debit - $credit, 2);
                        }
                    }
                }

                return $totals;
            },
        );
    }

    /**
     * How long a month read ending on this day is kept: briefly while it is
     * open, a week while recent, months once settled.
     */
    private function monthTtl(CarbonImmutable $to): \DateTimeInterface
    {
        $startOfThisMonth = CarbonImmutable::now()->startOfMonth();

        return match (true) {
            ! $to->lt($startOfThisMonth) => now()->addMinutes(self::OPEN_MONTH_MINUTES),
            $to->gte($startOfThisMonth->subMonths(self::RECENT_MONTHS)) => now()->addDays(self::RECENT_MONTH_DAYS),
            default => now()->addDays(self::SETTLED_MONTH_DAYS),
        };
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
     * The chart of accounts, for a report that names accounts which did not move.
     *
     * @return array<int, array{name: string, type: string, number: string, parent: string, passThrough: bool}>
     */
    public function chartOfAccounts(): array
    {
        return $this->accounts();
    }

    /**
     * Propertyware's chart of accounts, keyed by id, marking our own pass-through fees.
     *
     * @return array<int, array{name: string, type: string, number: string, parent: string, passThrough: bool}>
     */
    private function accounts(): array
    {
        return Cache::remember('propertyware:gl-accounts:owner-ledger:v3', now()->addDay(), function (): array {
            try {
                return collect($this->propertyware->listAll('accounting/glaccounts', [], 2000))
                    ->filter(fn (array $account): bool => filled($account['id'] ?? null))
                    ->mapWithKeys(fn (array $account): array => [(int) $account['id'] => [
                        'name' => trim((string) ($account['name'] ?? '')),
                        'type' => (string) ($account['accountType'] ?? ''),
                        'number' => trim((string) ($account['accountNumber'] ?? '')),
                        'parent' => trim((string) ($account['parentRef'] ?? '')),
                        // Under Management Income, or named for it at the top level
                        // as 4025 Management Income Admin Charge is.
                        'passThrough' => trim((string) ($account['parentRef'] ?? '')) === self::PASS_THROUGH_PARENT
                            || str_starts_with(trim((string) ($account['name'] ?? '')), self::PASS_THROUGH_PARENT),
                    ]])
                    ->all();
            } catch (PropertywareException) {
                return [];
            }
        });
    }
}
