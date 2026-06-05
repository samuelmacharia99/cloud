<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->string('direct_admin_package_name')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->dropColumn('direct_admin_package_name');
        });
    }
};
