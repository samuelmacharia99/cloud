<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->unsignedBigInteger('database_template_id')->nullable()->after('container_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_products', function (Blueprint $table) {
            $table->dropColumn('database_template_id');
        });
    }
};
