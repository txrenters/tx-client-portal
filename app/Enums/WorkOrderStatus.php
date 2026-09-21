<?php

namespace App\Enums;

/**
 * The client-facing status of a maintenance request.
 *
 * Scheduled work is an active request that has a scheduled date.
 */
enum WorkOrderStatus: string
{
    case Open = 'open';
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Canceled = 'canceled';

    /**
     * Derive the status from a Propertyware work order record.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        if (str_starts_with(strtolower(trim($record['status'] ?? '')), 'cancel')) {
            return self::Canceled;
        }

        if (filled($record['completedDate'] ?? null) || ($record['status'] ?? null) === 'Closed') {
            return self::Completed;
        }

        return filled($record['scheduledEndDate'] ?? null) ? self::Scheduled : self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::Canceled => 'Canceled',
        };
    }
}
