<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A month an owner was emailed their income statement, so a rerun never
 * emails the same month twice.
 */
#[Fillable(['client_account_id', 'month', 'portfolios'])]
class OwnerStatementEmail extends Model {}
