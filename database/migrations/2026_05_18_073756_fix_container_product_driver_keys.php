<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix any container_hosting products with wrong driver keys
        DB::table('products')
            ->where('type', 'container_hosting')
            ->whereNotNull('provisioning_driver_key')
            ->where('provisioning_driver_key', '!=', 'container')
            ->update(['provisioning_driver_key' => 'container']);

        // Ensure all container_hosting products without a driver key get the default
        DB::table('products')
            ->where('type', 'container_hosting')
            ->whereNull('provisioning_driver_key')
            ->update(['provisioning_driver_key' => 'container']);

        // Link container products to Node.js template if they don't have one
        $nodeTemplate = DB::table('container_templates')
            ->where('slug', 'nodejs')
            ->first();

        if ($nodeTemplate) {
            DB::table('products')
                ->where('type', 'container_hosting')
                ->whereNull('container_template_id')
                ->update(['container_template_id' => $nodeTemplate->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse - this fixes existing data
    }
};
