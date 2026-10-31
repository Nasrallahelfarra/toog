<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value platform settings managed by the admin.
 *
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('platform_settings', fn () => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('platform_settings');
    }

    /**
     * Default value for a per-store setting: admin override, else config.
     */
    public static function storeDefault(string $key): mixed
    {
        $defaults = array_replace_recursive(config('mojo.defaults'), static::get('store_defaults', []));

        return Arr::get($defaults, $key);
    }
}
