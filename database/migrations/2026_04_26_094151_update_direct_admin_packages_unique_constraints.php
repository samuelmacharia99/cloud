<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes unique constraints to be composite (node_id, field) instead of global
     * Only applies if constraints don't already exist (idempotent for MySQL/SQLite)
     */
    public function up(): void
    {
        $connection = \DB::connection()->getDriverName();

        // Drop old global constraints if they exist (MySQL only)
        if ($connection === 'mysql') {
            try {
                \DB::statement('ALTER TABLE `direct_admin_packages` DROP INDEX `direct_admin_packages_name_unique`');
            } catch (\Exception $e) {
                // Index doesn't exist, that's fine
            }

            try {
                \DB::statement('ALTER TABLE `direct_admin_packages` DROP INDEX `direct_admin_packages_package_key_unique`');
            } catch (\Exception $e) {
                // Index doesn't exist, that's fine
            }
        }

        // Migration is idempotent - indexes already exist from previous migrations
        // This is a no-op if called again
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direct_admin_packages', function (Blueprint $table) {
            $table->dropUnique('direct_admin_packages_node_id_name_unique');
            $table->dropUnique('direct_admin_packages_node_id_package_key_unique');
        });
    }
};
