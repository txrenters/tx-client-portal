<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A month a tenant was reminded their rent is due, so the daily check
 * reminds them at most once a month.
 */
#[Fillable(['client_account_id', 'propertyware_lease_id', 'month', 'balance'])]
class RentReminderNotice extends Model {}
