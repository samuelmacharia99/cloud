<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ENUM, so no-op for SQLite
        // MySQL needs the ENUM extended to include pending, provisioning, failed
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE services MODIFY COLUMN status ENUM('active','suspended','terminated','cancelled','pending','provisioning','failed') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE services MODIFY COLUMN status ENUM('active','suspended','terminated','cancelled') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
