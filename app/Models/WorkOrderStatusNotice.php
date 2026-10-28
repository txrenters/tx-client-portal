<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The last status of a work order a client was shown, so the half-hourly
 * check tells them when it is scheduled, completed or canceled, and only once.
 *
 * @property string $work_order_id
 * @property WorkOrderStatus $status
 */
#[Fillable(['client_account_id', 'work_order_id', 'status', 'notified_at'])]
class WorkOrderStatusNotice extends Model
{
    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'notified_at' => 'immutable_datetime',
        ];
    }
}
