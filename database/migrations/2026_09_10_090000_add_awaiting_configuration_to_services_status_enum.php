<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A stack that provisioned cleanly but cannot start until the customer
     * supplies their own credentials is not a failed service. SQLite has no
     * ENUM, so it needs no change.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE services MODIFY COLUMN status ENUM('active','suspended','terminated','cancelled','pending','provisioning','failed','awaiting_configuration') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('services')->where('status', 'awaiting_configuration')->update(['status' => 'failed']);

        DB::statement(
            "ALTER TABLE services MODIFY COLUMN status ENUM('active','suspended','terminated','cancelled','pending','provisioning','failed') NOT NULL DEFAULT 'pending'"
        );
    }
};
