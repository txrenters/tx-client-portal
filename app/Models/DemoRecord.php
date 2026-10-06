<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an App Review login created, kept in place of Propertyware or the hub.
 *
 * @property string $kind workorder, document, chat_thread or chat_message
 * @property int|null $external_id The id the demo hands out, 900500000 and up
 * @property array<string, mixed> $payload The record as the real API would return it
 */
#[Fillable(['kind', 'external_id', 'payload'])]
class DemoRecord extends Model
{
    /**
     * Where created records' ids start, past the made-up records and Propertyware's own.
     */
    public const FIRST_ID = 900500000;

    protected function casts(): array
    {
        return ['payload' => 'array', 'external_id' => 'integer'];
    }

    /**
     * Save a record, building its payload once its id is known.
     *
     * @param  Closure(int): array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function store(string $kind, Closure $payload): array
    {
        $record = static::create(['kind' => $kind, 'payload' => []]);
        $id = self::FIRST_ID + $record->id;
        $record->update(['external_id' => $id, 'payload' => $payload($id)]);

        return $record->payload;
    }

    /**
     * Every stored payload of one kind, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payloads(string $kind): array
    {
        return static::query()->where('kind', $kind)->orderBy('id')->pluck('payload')->all();
    }
}
