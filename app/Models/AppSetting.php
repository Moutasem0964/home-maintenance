<?php

namespace App\Models;

use App\Enums\SettingDataType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Tunable platform settings (commission rate, dispute window, ...). Read via AppSetting::get().
 *
 * @property SettingDataType $data_type
 * @property string $value
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'data_type', 'description'];

    protected function casts(): array
    {
        return ['data_type' => SettingDataType::class];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("app_setting.{$key}", 300, fn () => static::find($key));

        if ($setting === null) {
            return $default;
        }

        return match ($setting->data_type) {
            SettingDataType::Int => (int) $setting->value,
            SettingDataType::Decimal => (float) $setting->value,
            SettingDataType::Bool => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            default => $setting->value,
        };
    }
}
