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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type')->default('service')->nullable()->after('user_id');
        });

        // Backfill: invoices with notes like "Reseller Package%" → type = 'reseller_subscription'
        \DB::table('invoices')
            ->where('notes', 'like', 'Reseller Package%')
            ->update(['type' => 'reseller_subscription']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
