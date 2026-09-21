<?php

namespace App\Services\Propertyware\Data;

use App\Enums\ClientRole;

/**
 * A Propertyware contact: the tenant or owner a client login represents.
 */
class Contact
{
    /**
     * @param  array<int, int>  $leaseIds
     * @param  array<int, int>  $portfolioIds
     * @param  array<int, array{id: int, name: string}>  $portfolios
     */
    public function __construct(
        public readonly string $id,
        public readonly ?ClientRole $role,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
        public readonly array $leaseIds,
        public readonly array $portfolioIds,
        public readonly array $portfolios = [],
    ) {}

    /**
     * Build a contact from a Propertyware API record.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        return new self(
            id: (string) ($record['id'] ?? ''),
            role: match ($record['type'] ?? null) {
                'TENANT' => ClientRole::Resident,
                'OWNER' => ClientRole::Owner,
                default => null,
            },
            firstName: (string) ($record['firstName'] ?? ''),
            lastName: (string) ($record['lastName'] ?? ''),
            email: $record['email'] ?? null,
            leaseIds: self::attachedIds($record['attachedLeases'] ?? []),
            portfolioIds: self::attachedIds($record['attachedPortfolios'] ?? []),
            portfolios: collect(is_array($record['attachedPortfolios'] ?? null) ? $record['attachedPortfolios'] : [])
                ->filter(fn (mixed $portfolio): bool => is_array($portfolio) && is_numeric($portfolio['id'] ?? null))
                ->map(fn (array $portfolio): array => [
                    'id' => (int) $portfolio['id'],
                    'name' => filled($portfolio['name'] ?? null) ? (string) $portfolio['name'] : 'Portfolio '.$portfolio['id'],
                ])
                ->unique('id')->values()->all(),
        );
    }

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * Pull the ids out of an attached-record list such as attachedLeases.
     *
     * @param  mixed  $attached
     * @return array<int, int>
     */
    private static function attachedIds($attached): array
    {
        if (! is_array($attached)) {
            return [];
        }

        return collect($attached)
            ->pluck('id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
