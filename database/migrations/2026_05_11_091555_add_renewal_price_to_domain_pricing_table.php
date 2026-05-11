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
        Schema::table('domain_pricing', function (Blueprint $table) {
            $table->decimal('renewal_price', 10, 2)->nullable()->after('price')->comment('Annual renewal price for domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domain_pricing', function (Blueprint $table) {
            $table->dropColumn('renewal_price');
        });
    }
};
