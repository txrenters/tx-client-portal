<?php

namespace App\Models;

use Database\Factories\PropertywareApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of a work order's approval as Propertyware showed it, kept
 * because Propertyware overwrites the approval and its comment in place.
 *
 * @property string $work_order_id
 * @property ?string $work_order_number
 * @property bool $approved
 * @property ?Carbon $approved_date
 * @property ?string $approved_by
 * @property ?string $approved_by_email
 * @property ?string $approval_comment
 * @property ?Carbon $work_order_modified_at
 * @property Carbon $recorded_at
 */
#[Fillable([
    'work_order_id', 'work_order_number', 'approved', 'approved_date', 'approved_by', 'approved_by_email',
    'approval_comment', 'work_order_modified_at', 'recorded_at',
])]
class PropertywareApproval extends Model
{
    /** @use HasFactory<PropertywareApprovalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'approved' => 'boolean',
            'approved_date' => 'date',
            'work_order_modified_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
