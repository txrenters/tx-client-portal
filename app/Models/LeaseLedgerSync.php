<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How much of a lease's ledger we already hold, and when we last looked.
 *
 * @property string $lease_id
 * @property Carbon $synced_from
 * @property Carbon $synced_to
 * @property Carbon $synced_at
 */
#[Fillable(['lease_id', 'synced_from', 'synced_to', 'synced_at'])]
class LeaseLedgerSync extends Model
{
    protected $table = 'lease_ledger_syncs';

    protected $primaryKey = 'lease_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'synced_from' => 'date',
            'synced_to' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
