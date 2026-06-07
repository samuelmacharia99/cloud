<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE domains MODIFY COLUMN status ENUM('pending','active','expired','suspended') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "UPDATE domains SET status = 'active' WHERE status = 'pending'"
            );

            DB::statement(
                "ALTER TABLE domains MODIFY COLUMN status ENUM('active','expired','suspended') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
