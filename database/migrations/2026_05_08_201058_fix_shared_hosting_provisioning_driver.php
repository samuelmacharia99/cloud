<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Product;
use App\Models\DirectAdminPackage;

return new class extends Migration
{
    public function up(): void
    {
        // Fix shared hosting products: change provisioning_driver_key from 'cpanel' to 'directadmin'
        Product::where('type', 'shared_hosting')
            ->where('provisioning_driver_key', 'cpanel')
            ->update(['provisioning_driver_key' => 'directadmin']);

        // Assign a default DirectAdmin package to shared hosting products that don't have one
        $defaultPackage = DirectAdminPackage::where('package_key', 'starter')->first();

        if ($defaultPackage) {
            Product::where('type', 'shared_hosting')
                ->whereNull('direct_admin_package_id')
                ->update(['direct_admin_package_id' => $defaultPackage->id]);
        }
    }

    public function down(): void
    {
        Product::where('type', 'shared_hosting')
            ->where('provisioning_driver_key', 'directadmin')
            ->update(['provisioning_driver_key' => 'cpanel']);
    }
};
