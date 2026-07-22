<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domains')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE domains MODIFY COLUMN type ENUM('registration', 'transfer', 'dns') NOT NULL DEFAULT 'registration'");
        }
        // SQLite stores enums as strings — no schema change required.
    }

    public function down(): void
    {
        if (! Schema::hasTable('domains')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::table('domains')->where('type', 'dns')->update(['type' => 'registration']);
            DB::statement("ALTER TABLE domains MODIFY COLUMN type ENUM('registration', 'transfer') NOT NULL DEFAULT 'registration'");
        }
    }
};
