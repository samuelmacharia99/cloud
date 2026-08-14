<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use HasFactory;

    public const SENSITIVE_KEYS = [
        'sms_api_token',
        'smtp_password',
        'mpesa_passkey',
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_callback_token',
        'directadmin_api_password',
        'stripe_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
        'paypal_client_secret',
        'paypal_partner_client_secret',
        'recaptcha_secret_key',
        'telegram_bot_token',
        'cloudflare_api_token',
        'hetzner_storage_password',
    ];

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

        if ($value === null) {
            return $default;
        }

        return self::isSensitiveKey($key) ? self::decryptIfNeeded((string) $value) : $value;
    }

    public static function setValue($key, $value)
    {
        if (self::isSensitiveKey($key) && $value !== null && $value !== '') {
            $value = self::encryptIfNeeded((string) $value);
        }

        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget("setting:value:{$key}");

        return $setting;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    private static function encryptIfNeeded(string $value): string
    {
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (\Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private static function decryptIfNeeded(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
