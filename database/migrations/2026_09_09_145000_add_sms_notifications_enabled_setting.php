<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::updateOrCreate(
            ['key' => 'sms_notifications_enabled'],
            [
                'value' => '0',
                'description' => 'Send operational alerts (invoices, tickets, services) by SMS. When off, those alerts are email-only. Login and verification codes still follow SMS Configuration.',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'sms_notifications_enabled')->delete();
    }
};
