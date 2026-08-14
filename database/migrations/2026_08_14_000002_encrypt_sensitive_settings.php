<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sensitiveKeys = [
            'sms_api_token', 'smtp_password', 'mpesa_passkey', 'mpesa_consumer_key',
            'mpesa_consumer_secret', 'mpesa_callback_token', 'directadmin_api_password',
            'stripe_key', 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret',
            'paypal_partner_client_secret', 'recaptcha_secret_key', 'telegram_bot_token',
            'cloudflare_api_token', 'hetzner_storage_password',
        ];

        DB::table('settings')
            ->whereIn('key', $sensitiveKeys)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('key')
            ->each(function (object $setting): void {
                try {
                    Crypt::decryptString((string) $setting->value);
                } catch (Throwable) {
                    DB::table('settings')
                        ->where('key', $setting->key)
                        ->update(['value' => Crypt::encryptString((string) $setting->value)]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally retain encryption on rollback.
    }
};
