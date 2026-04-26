<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('direct_admin_packages', function (Blueprint $table) {
            // Drop the global unique constraints
            $table->dropUnique('direct_admin_packages_name_unique');
            $table->dropUnique('direct_admin_packages_package_key_unique');

            // Add composite unique constraints (node_id, field)
            // This allows same package across different nodes but prevents duplicates within a node
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
            // Drop the composite unique constraints
            $table->dropUnique('direct_admin_packages_node_id_name_unique');
            $table->dropUnique('direct_admin_packages_node_id_package_key_unique');

            // Add back the global unique constraints
            $table->unique('name');
            $table->unique('package_key');
        });
    }
};
