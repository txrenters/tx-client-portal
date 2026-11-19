{{--
    A tenant's ledger for a period: every charge, credit and payment, oldest
    first, with the same totals as the Lease page. Rendered by dompdf, so
    plain tables and inline styles only.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ledger {{ $ledger['from'] }} to {{ $ledger['to'] }}</title>
    <style>
        @page { size: letter portrait; margin: 28px 30px 40px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 8.5px; margin: 0; }
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
        .tiles td { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 10px; width: 50%; }
        .tiles .label { font-size: 8px; color: #6b7280; }
        .tiles .value { font-size: 15px; font-weight: bold; color: #183c90; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .lines th { font-size: 8px; text-align: left; padding: 4px 3px; border-bottom: 1px solid #9ca3af; color: #111827; }
        .lines td { padding: 4px 3px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .lines .amount { text-align: right; white-space: nowrap; width: 11%; }
        .lines .date { white-space: nowrap; width: 11%; }
        .lines .muted { color: #6b7280; }
        .lines tr.total td { font-weight: bold; border-top: 1.5px solid #374151; border-bottom: none; }
        .empty { margin-top: 14px; color: #6b7280; }
        .footer { margin-top: 16px; width: 100%; font-size: 7.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    @php
        $money = fn (float $amount): string => ($amount < 0 ? '-$' : '$').number_format(abs($amount), 2);
        $date = fn (string $day): string => \Carbon\CarbonImmutable::parse($day)->format('m/d/Y');
    @endphp

    <table class="brand">
        <tr>
            <td><img class="logo" src="{{ $logo }}" alt="TexasRenters.com"></td>
            <td class="title">
                <h1>Resident Ledger</h1>
                <p>Charges, credits and payments on your lease.</p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            @if ($property !== null)
                <td><strong>Property:</strong> {{ $property }}</td>
            @endif
            <td><strong>Period:</strong> {{ $date($ledger['from']) }} – {{ $date($ledger['to']) }}</td>
            <td style="text-align: right"><strong>Run date:</strong> {{ $generatedOn }}</td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            <td><div class="label">Charged</div><div class="value">{{ $money($ledger['charged']) }}</div></td>
            <td><div class="label">Paid</div><div class="value">{{ $money($ledger['paid']) }}</div></td>
        </tr>
    </table>

    @if ($ledger['entries'] === [])
        <p class="empty">Nothing was charged or paid in this period.</p>
    @else
        <table class="lines">
            <thead>
                <tr>
                    <th class="date">Date</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th class="amount">Charged</th>
                    <th class="amount">Paid</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ledger['entries'] as $entry)
                    <tr>
                        <td class="date">{{ $date($entry['date']) }}</td>
                        <td>
                            {{ $entry['description'] }}
                            @if (filled($entry['detail']))
                                <div class="muted">{{ $entry['detail'] }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $entry['type'] }}
                            @if ($entry['kind'] === 'prepayment')
                                <div class="muted">{{ $money($entry['amount']) }}, from money already paid</div>
                            @endif
                        </td>
                        <td class="amount">{{ $entry['sides'][0] === null ? '' : $money($entry['sides'][0]) }}</td>
                        <td class="amount">{{ $entry['sides'][1] === null ? '' : $money($entry['sides'][1]) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="3">Total</td>
                    <td class="amount">{{ $money($ledger['charged']) }}</td>
                    <td class="amount">{{ $money($ledger['paid']) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="footer">
        <tr>
            <td>Generated by the TexasRenters.com resident portal on {{ $generatedOn }}. A prepayment applied moves money you already paid onto a charge, so it counts as neither a charge nor a payment.</td>
            <td style="text-align: right">TexasRenters.com</td>
        </tr>
    </table>
</body>
</html>
