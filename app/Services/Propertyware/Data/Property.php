<?php

namespace App\Services\Propertyware\Data;

/**
 * A building or unit the client rents or owns.
 */
class Property
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly string $address,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $unitName = null,
        public readonly ?string $type = null,
        public readonly ?string $category = null,
        public readonly ?string $status = null,
        public readonly ?int $bedrooms = null,
        public readonly ?float $bathrooms = null,
        public readonly ?string $area = null,
        public readonly ?string $yearBuilt = null,
        public readonly ?string $neighborhood = null,
        public readonly ?string $county = null,
        public readonly bool $occupied = false,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record, ?string $unitName = null): self
    {
        $address = is_array($record['address'] ?? null) ? $record['address'] : [];

        return new self(
            id: (int) ($record['id'] ?? 0),
            name: filled($record['name'] ?? null) ? trim((string) $record['name'], " \t\n\r,;-") : null,
            address: trim((string) ($address['address'] ?? '')),
            city: $address['city'] ?? null,
            state: $address['stateRegion'] ?? null,
            postalCode: $address['postalCode'] ?? null,
            unitName: $unitName,
            type: $record['type'] ?? null,
            category: $record['category'] ?? null,
            status: $record['status'] ?? null,
            // Propertyware stores 0 when a size isn't filled in, so 0 means "not set".
            bedrooms: (int) ($record['numberOfBedrooms'] ?? 0) > 0 ? (int) $record['numberOfBedrooms'] : null,
            bathrooms: (float) ($record['numberOfBathrooms'] ?? 0) > 0 ? (float) $record['numberOfBathrooms'] : null,
            area: (float) ($record['totalArea'] ?? 0) > 0
                ? trim($record['totalArea'].' '.($record['areaUnits'] ?? ''))
                : null,
            yearBuilt: filled($record['yearBuilt'] ?? null) ? (string) $record['yearBuilt'] : null,
            neighborhood: $record['neighborhood'] ?? null,
            county: $record['county'] ?? null,
            occupied: filled($record['leaseID'] ?? null),
        );
    }

    /**
     * Get the address as one line, the way a client would write it.
     */
    public function fullAddress(): string
    {
        $cityLine = collect([$this->city, $this->state])->filter()->implode(', ');

        return collect([$this->address, $cityLine, $this->postalCode])
            ->filter()
            ->implode(' ');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postalCode' => $this->postalCode,
            'unitName' => $this->unitName,
            'fullAddress' => $this->fullAddress(),
            'type' => $this->type,
            'category' => $this->category,
            'status' => $this->status,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'area' => $this->area,
            'yearBuilt' => $this->yearBuilt,
            'neighborhood' => $this->neighborhood,
            'county' => $this->county,
            'occupied' => $this->occupied,
        ];
    }
}
