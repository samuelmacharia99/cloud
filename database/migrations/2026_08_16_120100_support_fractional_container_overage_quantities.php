<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Core-hours and GB-hours are metered fractions, not whole item counts.
            $table->decimal('quantity', 14, 4)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });
    }
};
