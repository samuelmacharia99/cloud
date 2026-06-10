<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->change();
            $table->foreign('reseller_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable(false)->change();
            $table->foreign('reseller_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
