<?php

namespace App\Services\Propertyware;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A tenant's ledger as a file to keep: a PDF in our letterhead to print or
 * hand to a landlord or bank, or a CSV to open in a spreadsheet.
 *
 * Made for the request and never kept. Web and API both go through here, so
 * the portal and the app hand out the same files.
 */
class LedgerDownloads
{
    /** The file types on offer. */
    public const FORMATS = ['pdf', 'csv'];

    /** What each kind of tenant ledger line is called in a download. */
    private const RESIDENT_KINDS = [
        'charge' => 'Charge',
        'credit' => 'Credit',
        'payment' => 'Payment',
        'return' => 'Returned payment',
        'prepayment' => 'Prepayment applied',
    ];

    /**
     * The tenant's ledger for a period, from LeaseLedger::export().
     *
     * @param  array{entries: array<int, array<string, mixed>>, charged: float, paid: float, from: string, to: string}  $ledger
     */
    public function resident(array $ledger, ?string $property, string $format): Response
    {
        $name = 'Ledger '.$this->period($ledger['from'], $ledger['to']).($property !== null ? ' - '.$this->safe($property) : '');

        if ($format === 'csv') {
            $rows = [['Date', 'Type', 'Description', 'Details', 'Reference', 'Amount', 'Charged', 'Paid']];

            foreach ($ledger['entries'] as $entry) {
                [$charged, $paid] = $this->residentSides($entry);
                $rows[] = [
                    $entry['date'],
                    self::RESIDENT_KINDS[$entry['kind']] ?? Str::headline((string) $entry['kind']),
                    $entry['description'],
                    $entry['detail'],
                    $entry['reference'],
                    $this->number($entry['amount']),
                    $charged === null ? '' : $this->number($charged),
                    $paid === null ? '' : $this->number($paid),
                ];
            }

            $rows[] = ['', 'Total', '', '', '', '', $this->number($ledger['charged']), $this->number($ledger['paid'])];

            return $this->csv($rows, $name);
        }

        return $this->pdf('pdf.resident-ledger', [
            'ledger' => [
                ...$ledger,
                'entries' => array_map(fn (array $entry): array => [
                    ...$entry,
                    'type' => self::RESIDENT_KINDS[$entry['kind']] ?? Str::headline((string) $entry['kind']),
                    'sides' => $this->residentSides($entry),
                ], $ledger['entries']),
            ],
            'property' => $property,
        ], $name, 'portrait');
    }

    /**
     * Which column a tenant's line counts in, matching the page's totals:
     * credits take away from what was charged, a returned payment from what
     * was paid, and a prepayment being applied counts on neither side.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: float|null, 1: float|null}
     */
    private function residentSides(array $entry): array
    {
        $amount = (float) $entry['amount'];

        return match ($entry['kind']) {
            'charge' => [$amount, null],
            'credit' => [-$amount, null],
            'payment' => [null, $amount],
            'return' => [null, -$amount],
            default => [null, null],
        };
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(array $rows, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('Could not open the response to write the spreadsheet.');
            }

            // So Excel reads the file as UTF-8 and shows accents and dashes as they are.
            fwrite($out, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($out, array_map($this->cell(...), $row), escape: '');
            }

            fclose($out);
        }, $name.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Text a spreadsheet would read as a formula is kept as plain text, so a
     * description from Propertyware can never run anything when it is opened.
     */
    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if (is_string($value) && ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $view, array $data, string $name, string $orientation): Response
    {
        return Pdf::loadView($view, [
            ...$data,
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('texas-renters-logo.png'))),
            'generatedOn' => CarbonImmutable::now(config('app.display_timezone'))->format('m/d/Y'),
        ])
            ->setPaper('letter', $orientation)
            // Only the letters used, not the whole font: a tenth of the size.
            ->setOption('isFontSubsettingEnabled', true)
            ->download($name.'.pdf')
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function number(float|int|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function period(string $from, string $to): string
    {
        return "{$from} to {$to}";
    }

    private function safe(string $name): string
    {
        return (string) Str::of($name)->replaceMatches('/[^A-Za-z0-9 ,.-]+/', '')->trim();
    }
}
