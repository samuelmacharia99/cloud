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
        // Add constraints to existing table
        if (Schema::hasTable('reseller_domain_pricing')) {
            Schema::table('reseller_domain_pricing', function (Blueprint $table) {
                // Add foreign keys if they don't exist
                try {
                    DB::statement('ALTER TABLE `reseller_domain_pricing` ADD CONSTRAINT `rdp_reseller_id_foreign` FOREIGN KEY (`reseller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE');
                } catch (\Exception $e) {
                    // Foreign key might already exist
                }

                try {
                    DB::statement('ALTER TABLE `reseller_domain_pricing` ADD CONSTRAINT `rdp_domain_extension_id_foreign` FOREIGN KEY (`domain_extension_id`) REFERENCES `domain_extensions` (`id`) ON DELETE CASCADE');
                } catch (\Exception $e) {
                    // Foreign key might already exist
                }

                try {
                    DB::statement('ALTER TABLE `reseller_domain_pricing` ADD UNIQUE `rdp_reseller_ext_period_unique` (`reseller_id`, `domain_extension_id`, `period_years`)');
                } catch (\Exception $e) {
                    // Unique constraint might already exist
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('reseller_domain_pricing')) {
            Schema::table('reseller_domain_pricing', function (Blueprint $table) {
                $table->dropUnique('rdp_reseller_ext_period_unique');
            });
        }
    }
};
