<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('bandwidth_overage_enabled')->default(false)->after('overage_enabled');
            $table->decimal('bandwidth_overage_rate', 10, 4)->nullable()->after('disk_overage_rate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['bandwidth_overage_enabled', 'bandwidth_overage_rate']);
        });
    }
};
