<?php

namespace App\Services\Propertyware;

use App\Services\Propertyware\Data\ClientScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An owner's income statement for one portfolio and one year, month by
 * month: Propertyware's Published Income Statement Standard By Month, cash
 * basis, read from the same books as the rest of the portal.
 *
 * Accounts are listed by name, each with its number, and an account's
 * sub-accounts sit under it with a subtotal, as Propertyware lays them out.
 * Web, API and PDF all go through here.
 */
class IncomeStatement
{
    public const BASIS = 'cash';

    public function __construct(private OwnerLedger $ledger, private OwnerStatement $statements) {}

    /**
     * The years a statement can be read for, newest first: the same years as
     * the monthly statements.
     *
     * @return array<int, int>
     */
    public function years(): array
    {
        return collect($this->statements->months())
            ->map(fn (array $month): int => (int) substr($month['value'], 0, 4))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The year asked for when it is one on offer, or the latest.
     */
    public function yearOrDefault(?int $year): int
    {
        $years = $this->years();

        return in_array($year, $years, strict: true) ? $year : $years[0];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function for(ClientScope $scope, int $portfolioId, int $year): array
    {
        $book = $this->ledger->accountsByMonth($scope, $portfolioId, $year);
        $monthKeys = array_keys($book['months']);

        $income = $this->section($book, 'Income');
        $expense = $this->section($book, 'Expense');
        $net = array_map(fn (float $in, float $out): float => round($in - $out, 2), $income['totals'], $expense['totals']);

        return [
            'portfolio' => collect($this->statements->portfolios($scope))->firstWhere('id', $portfolioId),
            'year' => $year,
            'basis' => self::BASIS,
            'from' => "{$year}-01-01",
            'to' => "{$year}-12-31",
            'months' => array_map(fn (string $month): array => [
                'value' => $month,
                'label' => strtoupper(CarbonImmutable::createFromFormat('!Y-m', $month)->format('M y')),
            ], $monthKeys),
            'income' => $income,
            'expense' => $expense,
            'netIncome' => $net,
            'totalIncome' => $income['total'],
            'totalExpense' => $expense['total'],
            'totalNetIncome' => round($income['total'] - $expense['total'], 2),
        ];
    }

    /**
     * The statement as a PDF in our letterhead, sent as a download. It is
     * made for the request and never stored: the owner keeps the only copy.
     *
     * @param  array<string, mixed>  $report
     */
    public function download(array $report): Response
    {
        $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('texas-renters-logo.png')));
        $name = Str::of($report['portfolio']['name'] ?? 'Portfolio')->replaceMatches('/[^A-Za-z0-9 ,.-]+/', '')->trim();

        return Pdf::loadView('pdf.income-statement', [
            'report' => $report,
            'logo' => $logo,
            'generatedOn' => CarbonImmutable::now(config('app.display_timezone'))->format('m/d/Y'),
        ])
            ->setPaper('letter', 'landscape')
            // Only the letters used, not the whole font: a tenth of the size.
            ->setOption('isFontSubsettingEnabled', true)
            ->download("Income Statement {$report['year']} - {$name}.pdf")
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * One side of the statement: its rows, and its total for each month and the year.
     *
     * @param  array{months: array<string, array<int, float>>, accounts: array<int, array{name: string, type: string, number: string, parent: string, passThrough: bool}>}  $book
     * @return array{rows: array<int, array{name: string, number: string, kind: string, depth: int, amounts: array<int, float>, total: float}>, totals: array<int, float>, total: float}
     */
    private function section(array $book, string $type): array
    {
        $accounts = array_filter(
            $book['accounts'],
            fn (array $account): bool => $type === 'Income' ? $account['type'] === 'Income' : str_contains($account['type'], 'Expense'),
        );

        $amounts = fn (int $id): array => array_map(
            fn (array $month): float => (float) ($month[$id] ?? 0),
            array_values($book['months']),
        );
        $row = fn (string $name, string $number, string $kind, int $depth, array $values): array => [
            'name' => $name,
            'number' => $number,
            'kind' => $kind,
            'depth' => $depth,
            'amounts' => $values,
            'total' => round(array_sum($values), 2),
        ];

        // Sub-accounts name their parent; a parent with none of its own
        // movement still heads its group, at nothing.
        $groups = [];

        foreach ($accounts as $id => $account) {
            $parent = $account['parent'];

            if ($parent === '') {
                $groups[$account['name']]['id'] ??= $id;
                $groups[$account['name']]['number'] = $account['number'];
            } else {
                $groups[$parent]['children'][$account['name']] = $id;
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];
        $zero = array_fill(0, count($book['months']), 0.0);
        $chart = $this->chart($type);

        foreach ($groups as $name => $group) {
            $own = isset($group['id']) ? $amounts($group['id']) : $zero;
            $number = $group['number'] ?? ($chart[$name] ?? '');
            $rows[] = $row($name, $number, 'account', 0, $own);

            if (! isset($group['children'])) {
                continue;
            }

            ksort($group['children'], SORT_NATURAL | SORT_FLAG_CASE);
            $subtotal = $own;

            foreach ($group['children'] as $childName => $childId) {
                $values = $amounts($childId);
                $rows[] = $row($childName, $accounts[$childId]['number'], 'account', 1, $values);
                $subtotal = array_map(fn (float $a, float $b): float => round($a + $b, 2), $subtotal, $values);
            }

            $rows[] = $row($name, '', 'subtotal', 0, $subtotal);
        }

        // Each account appears once, in its own row or its group; the totals
        // add the accounts, never the subtotals as well.
        $totals = $zero;

        foreach (array_keys($accounts) as $id) {
            $totals = array_map(fn (float $a, float $b): float => round($a + $b, 2), $totals, $amounts($id));
        }

        return ['rows' => $rows, 'totals' => $totals, 'total' => round(array_sum($totals), 2)];
    }

    /**
     * Account numbers by name for the parents of a group, which may not have
     * moved themselves.
     *
     * @return array<string, string>
     */
    private function chart(string $type): array
    {
        return collect($this->ledger->chartOfAccounts())
            ->filter(fn (array $account): bool => $account['parent'] === ''
                && ($type === 'Income' ? $account['type'] === 'Income' : str_contains($account['type'], 'Expense')))
            ->pluck('number', 'name')
            ->all();
    }
}
