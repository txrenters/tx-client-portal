<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;

/**
 * A client's own contact record as Propertyware holds it, grouped for the
 * "Your details on file" card so they can spot anything out of date.
 *
 * Only ever built from the signed-in client's own record.
 */
class ContactDetails
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<int, array{title: string, items: array<int, array{label: string, value: string}>}>
     */
    public static function for(array $record, ClientRole $viewer): array
    {
        $sections = [
            ['title' => 'Contact', 'items' => self::items([
                'Name' => trim(collect([$record['firstName'] ?? null, $record['middleName'] ?? null, $record['lastName'] ?? null])->filter()->implode(' ')) ?: null,
                'Company' => $record['company'] ?? null,
                'Email' => $record['email'] ?? null,
                'Alternate email' => $record['altEmail'] ?? null,
                'Mobile phone' => $record['mobilePhone'] ?? null,
                'Work phone' => $record['workPhone'] ?? null,
                'Home phone' => $record['homePhone'] ?? null,
                'Other phone' => $record['otherPhone'] ?? null,
                'Fax' => $record['fax'] ?? null,
            ])],
            ['title' => 'Mailing address', 'items' => self::items([
                'Address' => self::address(is_array($record['address'] ?? null) ? $record['address'] : []),
            ])],
        ];

        if ($viewer === ClientRole::Owner) {
            $sections[] = ['title' => 'Statements & payments', 'items' => self::items([
                'Name on checks' => $record['nameOnCheck'] ?? null,
                'Taxpayer name' => $record['ownerTaxpayerName'] ?? null,
                'Payment method' => filled($record['drawPaymentMethod'] ?? null) ? ucwords(strtolower(str_replace('_', ' ', (string) $record['drawPaymentMethod']))) : null,
                'Statements' => self::statementDelivery($record),
                'Receives a 1099' => isset($record['ownerEligible1099']) ? ($record['ownerEligible1099'] ? 'Yes' : 'No') : null,
            ])];
        }

        return array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array<int, array{label: string, value: string}>
     */
    private static function items(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null)
            ->filter()
            ->map(fn (string $value, string $label): array => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private static function address(array $address): ?string
    {
        $cityLine = trim(collect([$address['city'] ?? null, $address['stateRegion'] ?? null])->filter()->implode(', ').' '.($address['postalCode'] ?? ''));

        return collect([$address['address'] ?? null, $address['addressCont'] ?? null, $cityLine])->filter()->implode(', ') ?: null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function statementDelivery(array $record): ?string
    {
        $ways = collect([
            'ownerEmailStatement' => 'Email',
            'ownerPrintStatement' => 'Mail',
            'ownerPublishStatements' => 'Owner portal',
        ])->filter(fn (string $label, string $key): bool => (bool) ($record[$key] ?? false))->values();

        return $ways->isEmpty() ? null : $ways->implode(', ');
    }
}
