<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A client closing an announcement, so it stays closed on every device.
 *
 * @property int $announcement_id
 * @property int $user_id
 */
#[Fillable(['announcement_id', 'user_id'])]
class AnnouncementDismissal extends Model {}
