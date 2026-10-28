<?php

namespace App\Services\Propertyware\Data;

use Carbon\CarbonImmutable;

/**
 * A tenant's lease, as the portal is allowed to show it.
 *
 * Propertyware's record also carries the owner's entity in `location` and the
 * other people on the lease in `contacts`; neither leaves this class, so a
 * tenant never reads who owns their home or who else signed. The lease's own
 * name is the household's, so it stays.
 */
class Lease
{
    public function __construct(
        public readonly int $id,
        /** Propertyware's label for the lease, e.g. "Vega, Y." */
        public readonly ?string $name,
        public readonly ?string $status,
        public readonly bool $active,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $moveInDate,
        public readonly ?string $moveOutDate,
        public readonly ?string $noticeGivenDate,
        public readonly ?float $rent,
        public readonly ?float $balance,
        public readonly ?string $lateFeeRule,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromArray(array $record): self
    {
        return new self(
            id: (int) ($record['id'] ?? 0),
            name: filled($record['leaseName'] ?? null) ? (string) $record['leaseName'] : null,
            status: filled($record['status'] ?? null) ? (string) $record['status'] : null,
            active: (bool) ($record['active'] ?? false),
            startDate: self::date($record['startDate'] ?? null),
            endDate: self::date($record['endDate'] ?? null),
            moveInDate: self::date($record['moveInDate'] ?? null),
            // The move-out on the books, or the one the tenant has given notice for.
            moveOutDate: self::date($record['moveOutDate'] ?? null) ?? self::date($record['scheduleMoveOutDate'] ?? null),
            noticeGivenDate: self::date($record['noticeGivenDate'] ?? null),
            rent: is_numeric($record['baseRent'] ?? null) ? (float) $record['baseRent'] : null,
            balance: is_numeric($record['leaseBalance'] ?? null) ? (float) $record['leaseBalance'] : null,
            lateFeeRule: filled($record['lateFeeRule'] ?? null) ? (string) $record['lateFeeRule'] : null,
        );
    }

    /**
     * Whether Propertyware has closed the lease out; the tenancy is over.
     */
    public function isTerminated(): bool
    {
        return strcasecmp((string) $this->status, 'Terminated') === 0;
    }

    /**
     * The last day of the month rent can be paid without a late fee, from a
     * rule such as "7.00% of rent charges after due day 3, …"; null when the
     * rule does not say.
     */
    public function lateFeeGraceDay(): ?int
    {
        if ($this->lateFeeRule === null || ! preg_match('/after due day (\d{1,2})\b/i', $this->lateFeeRule, $match)) {
            return null;
        }

        $day = (int) $match[1];

        return $day >= 1 && $day <= 28 ? $day : null;
    }

    /**
     * Whether the lease is running out: it ends, or they move out, within 60 days.
     */
    public function endsSoon(): bool
    {
        $ending = $this->moveOutDate ?? $this->endDate;

        if ($ending === null || ! $this->active) {
            return false;
        }

        return CarbonImmutable::parse($ending)->isBefore(CarbonImmutable::now()->addDays(60));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'active' => $this->active,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'moveInDate' => $this->moveInDate,
            'moveOutDate' => $this->moveOutDate,
            'noticeGivenDate' => $this->noticeGivenDate,
            'rent' => $this->rent,
            'balance' => $this->balance,
            'lateFeeRule' => $this->lateFeeRule,
            'endsSoon' => $this->endsSoon(),
        ];
    }

    /** Propertyware sends dates as Y-m-d, and nothing when they are not set. */
    private static function date(mixed $value): ?string
    {
        return filled($value) ? substr((string) $value, 0, 10) : null;
    }
}
