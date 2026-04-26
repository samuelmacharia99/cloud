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
        // Get existing indexes to check what needs to be dropped
        $sm = \DB::connection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes('direct_admin_packages');

        Schema::table('direct_admin_packages', function (Blueprint $table) use ($indexes) {
            // Drop existing global unique constraints if they exist
            if (isset($indexes['direct_admin_packages_name_unique'])) {
                $table->dropUnique('direct_admin_packages_name_unique');
            } elseif (isset($indexes['name'])) {
                $table->dropUnique('name');
            }

            if (isset($indexes['direct_admin_packages_package_key_unique'])) {
                $table->dropUnique('direct_admin_packages_package_key_unique');
            } elseif (isset($indexes['package_key'])) {
                $table->dropUnique('package_key');
            }

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
        // Get existing indexes to check what needs to be dropped
        $sm = \DB::connection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes('direct_admin_packages');

        Schema::table('direct_admin_packages', function (Blueprint $table) use ($indexes) {
            if (isset($indexes['direct_admin_packages_node_id_name_unique'])) {
                $table->dropUnique('direct_admin_packages_node_id_name_unique');
            } elseif (isset($indexes['node_id_name'])) {
                $table->dropUnique('node_id_name');
            }

            if (isset($indexes['direct_admin_packages_node_id_package_key_unique'])) {
                $table->dropUnique('direct_admin_packages_node_id_package_key_unique');
            } elseif (isset($indexes['node_id_package_key'])) {
                $table->dropUnique('node_id_package_key');
            }
        });
    }
};
