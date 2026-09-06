<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('da_convert_batch_items', function (Blueprint $table) {
            $table->foreignId('reseller_product_id')->nullable()->after('service_id')->constrained('reseller_products')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->after('reseller_product_id')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('da_convert_batch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('reseller_product_id');
        });
    }
};
