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
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','unpaid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid'");
        }
        // SQLite stores enum as text — no structural change needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid'");
        }
    }
};
