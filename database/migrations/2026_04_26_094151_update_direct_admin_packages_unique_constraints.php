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
        // Drop old global constraints if they exist
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

        // Check if composite constraints already exist before adding
        $indexes = \DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'direct_admin_packages' AND COLUMN_NAME IN ('node_id', 'name')");
        $hasNodeIdNameConstraint = collect($indexes)->contains(fn($idx) => str_contains($idx->CONSTRAINT_NAME, 'node_id_name'));

        $indexes = \DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'direct_admin_packages' AND COLUMN_NAME IN ('node_id', 'package_key')");
        $hasNodeIdPackageKeyConstraint = collect($indexes)->contains(fn($idx) => str_contains($idx->CONSTRAINT_NAME, 'node_id_package_key'));

        Schema::table('direct_admin_packages', function (Blueprint $table) use ($hasNodeIdNameConstraint, $hasNodeIdPackageKeyConstraint) {
            // Add composite unique constraints if they don't already exist
            if (!$hasNodeIdNameConstraint) {
                $table->unique(['node_id', 'name']);
            }
            if (!$hasNodeIdPackageKeyConstraint) {
                $table->unique(['node_id', 'package_key']);
            }
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
