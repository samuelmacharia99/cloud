<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add credential columns first
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('ssh_username')->nullable()->after('ssh_port');
            $table->text('ssh_password')->nullable()->after('ssh_username');
            $table->text('da_login_key')->nullable()->after('ssh_password');
            $table->string('da_port', 10)->nullable()->default('2222')->after('da_login_key');
        });

        // Alter type ENUM to add 'directadmin' (only for MySQL/MariaDB)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE nodes MODIFY COLUMN type ENUM('dedicated_server','container_host','load_balancer','database_server','directadmin') NOT NULL DEFAULT 'dedicated_server'");
        }
    }

    public function down(): void
    {
        // Revert type ENUM back to original (only for MySQL/MariaDB)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE nodes MODIFY COLUMN type ENUM('dedicated_server','container_host','load_balancer','database_server') NOT NULL DEFAULT 'dedicated_server'");
        }

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['ssh_username', 'ssh_password', 'da_login_key', 'da_port']);
        });
    }
};
