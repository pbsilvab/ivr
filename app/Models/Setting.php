<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic key/value store.
 *
 * @deprecated Unused. The provisioning commands used to write the Twilio SIDs here while the
 * rest of the app read them from `config('services.twilio.*')`, which left two sources of
 * truth and only one of them consulted. The commands now print the SIDs for `.env` instead,
 * making config the single source. Nothing reads this model any more.
 *
 * The class and its table are kept rather than dropped so no migration has to be reversed on
 * an existing install; remove both once no environment depends on the table existing.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
