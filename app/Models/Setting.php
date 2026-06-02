<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    // The primary key is 'key', not 'id'
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    public $timestamps = false;

    public static function getValue($key, $default = null)
    {
        $cacheKey = "setting:value:{$key}";

        $value = Cache::rememberForever($cacheKey, function () use ($key) {
            return self::where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    public static function setValue($key, $value)
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget("setting:value:{$key}");

        return $setting;
    }
}
