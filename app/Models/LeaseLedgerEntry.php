<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One charge, payment or prepayment on a lease, as we hold it locally.
 *
 * Propertyware stays the source of truth; this is the copy the portal reads so
 * a tenant's history doesn't cost a dozen SOAP calls every time they open it.
 */
#[Fillable(['lease_id', 'external_id', 'occurred_on', 'kind', 'direction', 'amount', 'description', 'detail', 'reference'])]
class LeaseLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
