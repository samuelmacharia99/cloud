<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes unique constraints to be composite (node_id, field) instead of global
     */
    public function up(): void
    {
        // Use raw SQL to check and drop constraints safely
        $connection = \DB::connection()->getDriverName();

        if ($connection === 'mysql') {
            // Check if constraints exist and drop them
            \DB::statement('ALTER TABLE direct_admin_packages DROP INDEX IF EXISTS direct_admin_packages_name_unique');
            \DB::statement('ALTER TABLE direct_admin_packages DROP INDEX IF EXISTS direct_admin_packages_package_key_unique');
        }

        Schema::table('direct_admin_packages', function (Blueprint $table) {
            // Add composite unique constraints so packages can exist on multiple nodes
            // but are unique within each node
            $table->unique(['node_id', 'name']);
            $table->unique(['node_id', 'package_key']);
        });
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
