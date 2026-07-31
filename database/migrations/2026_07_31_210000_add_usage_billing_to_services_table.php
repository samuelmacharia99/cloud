<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('package')->after('billing_cycle');
            $table->json('included_limits')->nullable()->after('custom_price');
            $table->json('usage_rates')->nullable()->after('included_limits');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'included_limits', 'usage_rates']);
        });
    }
};
