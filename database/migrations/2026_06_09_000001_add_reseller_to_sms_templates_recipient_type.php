<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE sms_templates MODIFY recipient_type ENUM('customer', 'admin', 'reseller', 'both') NOT NULL DEFAULT 'customer'"
        );
    }

    public function down(): void
    {
        DB::table('sms_templates')
            ->where('recipient_type', 'reseller')
            ->update(['recipient_type' => 'admin']);

        DB::statement(
            "ALTER TABLE sms_templates MODIFY recipient_type ENUM('customer', 'admin', 'both') NOT NULL DEFAULT 'customer'"
        );
    }
};
