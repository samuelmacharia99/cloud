<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The original `nodes.type` column was an ENUM that didn't include
     * `directadmin`. The MySQL-only follow-up migration extended it, but
     * SQLite's CHECK constraint was never updated — so dev environments
     * can't create DA nodes. Switching to a plain string keeps both engines
     * happy and lets us add new node types without rewriting CHECK clauses.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: rebuild the column without the CHECK clause.
            Schema::table('nodes', function (Blueprint $table) {
                $table->string('type', 50)->default('dedicated_server')->change();
            });
        } else {
            // MySQL/MariaDB: relax the ENUM to a plain VARCHAR.
            DB::statement("ALTER TABLE nodes MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'dedicated_server'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('nodes', function (Blueprint $table) {
                $table->enum('type', [
                    'dedicated_server',
                    'container_host',
                    'load_balancer',
                    'database_server',
                    'directadmin',
                ])->default('dedicated_server')->change();
            });
        } else {
            DB::statement("ALTER TABLE nodes MODIFY COLUMN type ENUM('dedicated_server','container_host','load_balancer','database_server','directadmin') NOT NULL DEFAULT 'dedicated_server'");
        }
    }
};
