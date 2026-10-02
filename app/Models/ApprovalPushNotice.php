<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A work order an owner has already been told is waiting for their approval,
 * so the half-hourly check never tells them about it twice.
 */
#[Fillable(['client_account_id', 'work_order_id', 'pushed_at'])]
class ApprovalPushNotice extends Model
{
    protected function casts(): array
    {
        return [
            'pushed_at' => 'immutable_datetime',
        ];
    }
}
