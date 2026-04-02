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
        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->default('shared_hosting')->after('category');
            $table->decimal('monthly_price', 10, 2)->nullable()->after('price');
            $table->decimal('yearly_price', 10, 2)->nullable()->after('monthly_price');
            $table->string('provisioning_driver_key')->nullable()->after('yearly_price');
            $table->json('resource_limits')->nullable()->after('provisioning_driver_key');
            $table->boolean('visible_to_resellers')->default(false)->after('resource_limits');
            $table->boolean('featured')->default(false)->after('visible_to_resellers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'monthly_price',
                'yearly_price',
                'provisioning_driver_key',
                'resource_limits',
                'visible_to_resellers',
                'featured',
            ]);
        });
    }
};
