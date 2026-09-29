<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A value staff change from the admin area, such as the referral reward text.
 *
 * @property string $key
 * @property ?string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * The stored value, or the fallback when staff have not set one.
     */
    public static function valueOf(string $key, ?string $fallback = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $fallback;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
