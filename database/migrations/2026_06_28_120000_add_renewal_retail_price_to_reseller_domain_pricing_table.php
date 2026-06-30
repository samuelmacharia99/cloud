<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_domain_pricing', function (Blueprint $table) {
            $table->decimal('renewal_retail_price', 10, 2)
                ->nullable()
                ->after('retail_price')
                ->comment('Customer renewal price for this period; defaults to retail_price when null');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_domain_pricing', function (Blueprint $table) {
            $table->dropColumn('renewal_retail_price');
        });
    }
};
