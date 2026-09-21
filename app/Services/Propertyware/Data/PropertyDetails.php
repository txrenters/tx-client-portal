<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The extra building information shown on a property page, grouped into
 * sections and trimmed to what the viewing client may see.
 *
 * Entry codes (lockbox, alarm, gate, garage, mailbox) and staff-only
 * make-ready notes are never shared with anyone through the portal.
 */
class PropertyDetails
{
    /**
     * Custom fields, by their Propertyware name, grouped into sections per viewer.
     *
     * @var array<string, array{owner: bool, resident: bool, fields: array<string, string>}>
     */
    private const CUSTOM_SECTIONS = [
        'Management' => ['owner' => true, 'resident' => false, 'fields' => [
            'Management Plan' => 'Management plan',
            'Leasing Charge Plan' => 'Leasing charge plan',
            'Date PM Agreement Updated' => 'Management agreement updated',
            'Home Warranty' => 'Home warranty',
            'HVAC Plan' => 'HVAC plan',
            'Owner Pet Prefences' => 'Pet preferences',
        ]],
        'Home systems' => ['owner' => true, 'resident' => true, 'fields' => [
            'HVAC Filter Location Information' => 'HVAC filter location',
            'Breaker Box Location' => 'Breaker box',
            'Water Shut Off Valve Location' => 'Water shut-off',
            'Gas Shut Off Valve Location' => 'Gas shut-off',
            'Fireplace' => 'Fireplace',
            'Included Appliances' => 'Included appliances',
        ]],
        'Utilities & services' => ['owner' => true, 'resident' => true, 'fields' => [
            'Utilities' => 'Utilities',
            'Water Provider' => 'Water provider',
            'Gas Provider' => 'Gas provider',
            'Trash Provider' => 'Trash provider',
            'Utilities Handled by HOA' => 'Utilities handled by HOA',
            'Pool Service' => 'Pool service',
            'Yard Care During Lease' => 'Yard care',
            'Yard Features' => 'Yard features',
        ]],
        'Inspections' => ['owner' => true, 'resident' => false, 'fields' => [
            'Last Occupied Inspection' => 'Last occupied inspection',
            'Last HVAC Inspection' => 'Last HVAC inspection',
            'Last Property Code Work Completed_Inspected' => 'Last code work / inspection',
            'Last Winterization' => 'Last winterization',
            'Last Filter Delivery' => 'Last filter delivery',
        ]],
        'Pets' => ['owner' => false, 'resident' => true, 'fields' => [
            'Pet Restrictions' => 'Pet restrictions',
        ]],
    ];

    /**
     * Get the property page sections for this viewer, leaving out empty ones.
     *
     * @param  array<string, mixed>  $building
     * @return array{sections: array<int, array{title: string, items: array<int, array{label: string, value: string}>}>, amenities: array<int, string>, managers: array<int, array{name: string, email: ?string}>}
     */
    public static function for(array $building, ClientRole $viewer): array
    {
        $custom = collect($building['customFields'] ?? [])
            ->filter(fn (mixed $field): bool => is_array($field) && filled($field['value'] ?? null))
            ->mapWithKeys(fn (array $field): array => [(string) $field['fieldName'] => self::formatValue($field['value'], $field['dataType'] ?? null)]);

        $sections = [];

        if ($viewer === ClientRole::Owner) {
            $sections[] = ['title' => 'Leasing', 'items' => self::items([
                'Target rent' => self::money($building['targetRent'] ?? null, $building['targetRentUnits'] ?? null),
                'Target deposit' => self::money($building['targetDepositAmount'] ?? $building['targetDeposit'] ?? null),
                'Available' => self::formatValue($building['marketing']['availableDate'] ?? null, 'DATE'),
                'Lease terms' => self::leaseTerms($building['marketing'] ?? []),
                'Pets allowed' => self::pets($building['marketing'] ?? []),
                'Smoking allowed' => self::yesNo($building['marketing']['smokingAllowed'] ?? null),
                'Maintenance approval limit' => self::money($building['maintenanceSpendingLimitAmount'] ?? null, $building['maintenanceSpendingLimitTime'] ?? null),
            ])];
        }

        foreach (self::CUSTOM_SECTIONS as $title => $section) {
            if (! $section[$viewer === ClientRole::Owner ? 'owner' : 'resident']) {
                continue;
            }

            $items = collect($section['fields'])->map(fn (string $label, string $field): ?string => $custom[$field] ?? null)->all();

            if ($title === 'Home systems') {
                $items['HVAC filter sizes'] = collect(range(1, 4))->map(fn (int $n): ?string => $custom["HVAC Filter Size {$n}"] ?? null)->filter()->implode(', ') ?: null;
            }

            $sections[] = ['title' => $title, 'items' => self::items(collect($items)->mapWithKeys(
                fn (?string $value, string $field): array => [$section['fields'][$field] ?? $field => $value],
            )->all())];
        }

        return [
            'sections' => array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== [])),
            'amenities' => collect($building['amenities'] ?? [])->pluck('name')->filter()->values()->all(),
            'managers' => collect($building['propertyManagerList'] ?? [])
                ->filter(fn (mixed $manager): bool => is_array($manager) && filled($manager['name'] ?? null))
                ->map(fn (array $manager): array => ['name' => $manager['name'], 'email' => $manager['email'] ?? null])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array<int, array{label: string, value: string}>
     */
    private static function items(array $values): array
    {
        return collect($values)
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value, string $label): array => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    private static function formatValue(mixed $value, ?string $dataType): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_bool($value) || in_array(strtolower((string) $value), ['true', 'false'], true)) {
            return self::yesNo($value);
        }

        if (strtoupper((string) $dataType) === 'DATE') {
            try {
                return Carbon::parse((string) $value)->format('M j, Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return trim((string) $value);
    }

    private static function yesNo(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    private static function money(mixed $amount, mixed $per = null): ?string
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        $formatted = '$'.number_format((float) $amount, fmod((float) $amount, 1.0) === 0.0 ? 0 : 2);

        return filled($per) ? $formatted.' / '.strtolower(str_replace('_', ' ', (string) $per)) : $formatted;
    }

    /**
     * @param  array<string, mixed>  $marketing
     */
    private static function leaseTerms(array $marketing): ?string
    {
        return filled($marketing['leaseTerms'] ?? null)
            ? trim($marketing['leaseTerms'].' '.strtolower((string) ($marketing['leaseTermsUnits'] ?? '')))
            : null;
    }

    /**
     * @param  array<string, mixed>  $marketing
     */
    private static function pets(array $marketing): ?string
    {
        if (! filter_var($marketing['petsAllowed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return isset($marketing['petsAllowed']) ? 'No' : null;
        }

        $kinds = collect(['petDogAllowed' => 'dogs', 'petCatAllowed' => 'cats', 'petOtherAllowed' => 'other pets'])
            ->filter(fn (string $label, string $key): bool => filter_var($marketing[$key] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->values();

        return $kinds->isEmpty() ? 'Yes' : 'Yes: '.$kinds->implode(', ');
    }
}
