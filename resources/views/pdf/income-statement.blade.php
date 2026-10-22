{{--
    An owner's income statement for one portfolio and one year, month by month:
    the same report as Propertyware's Published Income Statement, in our own
    letterhead. Rendered by dompdf, so plain tables and inline styles only.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Income Statement {{ $report['label'] }} – {{ $report['portfolio']['name'] ?? 'Portfolio' }}</title>
    <style>
        @page { size: letter landscape; margin: 28px 30px 40px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 8px; margin: 0; }
        .brand { width: 100%; border-bottom: 3px solid #183c90; padding-bottom: 8px; }
        .brand td { vertical-align: bottom; }
        .logo { height: 34px; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 16px; color: #183c90; }
        .title p { margin: 2px 0 0; font-size: 8px; color: #6b7280; }
        .meta { width: 100%; margin-top: 10px; }
        .meta td { font-size: 9px; padding: 1px 0; }
        .meta strong { color: #111827; }
        .tiles { width: 100%; margin-top: 10px; border-collapse: separate; border-spacing: 6px 0; }
        .tiles td { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 10px; width: 33%; }
        .tiles .label { font-size: 8px; color: #6b7280; }
        .tiles .value { font-size: 15px; font-weight: bold; color: #183c90; }
        h2 { font-size: 11px; margin: 14px 0 4px; color: #111827; }
        /* Fixed columns, so Income, Expense and the net lines all line up. */
        table.report, table.net { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .report th { font-size: 7.5px; text-align: right; padding: 4px 3px; border-bottom: 1px solid #9ca3af; color: #111827; }
        .report th.account, .report td.account, .net td.account { text-align: left; width: 16%; }
        .report td { text-align: right; padding: 3px; border-bottom: 1px solid #e5e7eb; }
        .report td.account { white-space: normal; color: #374151; }
        .report tr.child td.account { padding-left: 12px; }
        .report tr.subtotal td { font-weight: bold; }
        .report tr.total td { font-weight: bold; border-top: 1.5px solid #374151; border-bottom: none; }
        .report td.year, .report th.year, .net td.year { background: #f3f6fc; }
        table.net { margin-top: 12px; }
        .net td { text-align: right; padding: 5px 3px; font-weight: bold; border-top: 1.5px solid #183c90; border-bottom: 1.5px solid #183c90; }
        .footer { margin-top: 16px; width: 100%; font-size: 7.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    @php
        $money = fn (float $amount): string => ($amount < 0 ? '-$' : '$').number_format(abs($amount), 2);
    @endphp

    <table class="brand">
        <tr>
            <td><img class="logo" src="{{ $logo }}" alt="TexasRenters.com"></td>
            <td class="title">
                <h1>Income Statement by Month – {{ $report['label'] }}</h1>
                <p>A 12-month summary of income and expense. This report is available for either cash or accrual accounting basis.</p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td><strong>Portfolio:</strong> {{ $report['portfolio']['name'] ?? '' }}</td>
            <td><strong>Report period:</strong> {{ \Carbon\CarbonImmutable::parse($report['from'])->format('m/d/Y') }} – {{ \Carbon\CarbonImmutable::parse($report['to'])->format('m/d/Y') }}</td>
            <td><strong>Accounting basis:</strong> {{ ucfirst($report['basis']) }}</td>
            <td style="text-align: right"><strong>Run date:</strong> {{ $generatedOn }}</td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            <td><div class="label">Total Income</div><div class="value">{{ $money($report['totalIncome']) }}</div></td>
            <td><div class="label">Total Expense</div><div class="value">{{ $money($report['totalExpense']) }}</div></td>
            <td><div class="label">Total Net Income</div><div class="value">{{ $money($report['totalNetIncome']) }}</div></td>
        </tr>
    </table>

    @foreach (['income' => 'Income', 'expense' => 'Expense'] as $section => $heading)
        <h2>{{ $heading }}</h2>
        <table class="report">
            <thead>
                <tr>
                    <th class="account">Account</th>
                    @foreach ($report['months'] as $month)
                        <th>{{ $month['label'] }}</th>
                    @endforeach
                    <th class="year">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report[$section]['rows'] as $row)
                    <tr class="{{ $row['kind'] === 'subtotal' ? 'subtotal' : '' }} {{ $row['depth'] > 0 ? 'child' : '' }}">
                        <td class="account">{{ $row['kind'] === 'subtotal' ? 'Subtotal '.$row['name'] : trim($row['name'].' '.$row['number']) }}</td>
                        @foreach ($row['amounts'] as $amount)
                            <td>{{ $money($amount) }}</td>
                        @endforeach
                        <td class="year">{{ $money($row['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="account">Total {{ $heading }}</td>
                    @foreach ($report[$section]['totals'] as $amount)
                        <td>{{ $money($amount) }}</td>
                    @endforeach
                    <td class="year">{{ $money($report[$section]['total']) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <table class="net">
        <tr>
            <td class="account">Net Operating Income</td>
            @foreach ($report['netIncome'] as $amount)
                <td>{{ $money($amount) }}</td>
            @endforeach
            <td class="year">{{ $money($report['totalNetIncome']) }}</td>
        </tr>
        <tr>
            <td class="account">Total Net Income</td>
            @foreach ($report['netIncome'] as $amount)
                <td>{{ $money($amount) }}</td>
            @endforeach
            <td class="year">{{ $money($report['totalNetIncome']) }}</td>
        </tr>
    </table>

    <table class="footer">
        <tr>
            <td>Generated by the TexasRenters.com owner portal on {{ $generatedOn }}. Figures come from your account's books with TexasRenters.com and are provided for your records; they are not tax advice.</td>
            <td style="text-align: right">TexasRenters.com</td>
        </tr>
    </table>
</body>
</html>
