<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['telegram_monitor_enabled', '0', 'Enable Telegram monitoring alerts'],
            ['telegram_bot_token', '', 'Telegram bot token from @BotFather'],
            ['telegram_chat_id', '', 'Telegram chat ID for admin alerts'],
            ['telegram_monitor_errors', '1', 'Telegram: application log errors'],
            ['telegram_monitor_payments', '1', 'Telegram: payments and invoices'],
            ['telegram_monitor_services', '1', 'Telegram: service lifecycle events'],
            ['telegram_monitor_orders', '1', 'Telegram: new orders'],
            ['telegram_monitor_registrations', '1', 'Telegram: new registrations'],
            ['telegram_monitor_tickets', '1', 'Telegram: support tickets'],
            ['telegram_monitor_resellers', '1', 'Telegram: reseller events'],
            ['telegram_monitor_system', '1', 'Telegram: cron, nodes, containers'],
            ['telegram_log_monitor_offset', '0', 'Byte offset for laravel.log monitoring'],
        ];

        foreach ($defaults as [$key, $value, $description]) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'description' => $description,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'telegram_monitor_enabled',
            'telegram_bot_token',
            'telegram_chat_id',
            'telegram_monitor_errors',
            'telegram_monitor_payments',
            'telegram_monitor_services',
            'telegram_monitor_orders',
            'telegram_monitor_registrations',
            'telegram_monitor_tickets',
            'telegram_monitor_resellers',
            'telegram_monitor_system',
            'telegram_log_monitor_offset',
        ])->delete();
    }
};
