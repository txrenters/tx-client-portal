<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One lease, for one end date, that the 90-day reminder has dealt with.
 *
 * An extension gives the lease a new end date, and so a new row and a new
 * reminder 90 days before it.
 *
 * @property CarbonImmutable $end_date
 */
#[Fillable(['propertyware_lease_id', 'end_date', 'notified_at', 'recipients'])]
class LeaseExpiryNotice extends Model
{
    protected function casts(): array
    {
        return [
            'end_date' => 'immutable_date',
            'notified_at' => 'immutable_datetime',
        ];
    }
}
