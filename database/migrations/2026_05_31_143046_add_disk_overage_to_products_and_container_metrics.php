<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('disk_overage_rate', 10, 4)->default(0)->after('ram_overage_rate'); // KES per GB-hour
        });

        Schema::table('container_metrics', function (Blueprint $table) {
            $table->decimal('disk_used_gb', 10, 4)->default(0)->after('block_io_write_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('disk_overage_rate');
        });

        Schema::table('container_metrics', function (Blueprint $table) {
            $table->dropColumn('disk_used_gb');
        });
    }
};
