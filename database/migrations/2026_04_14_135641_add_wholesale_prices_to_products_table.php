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
            $table->decimal('wholesale_monthly_price', 10, 2)->nullable()->after('monthly_price');
            $table->decimal('wholesale_yearly_price', 10, 2)->nullable()->after('wholesale_monthly_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('wholesale_monthly_price');
            $table->dropColumn('wholesale_yearly_price');
        });
    }
};
