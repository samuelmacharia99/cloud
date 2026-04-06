<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cpu_overage_rate', 10, 4)->default(0)->after('container_template_id'); // KES per core-hour
            $table->decimal('ram_overage_rate', 10, 4)->default(0)->after('cpu_overage_rate'); // KES per GB-hour
            $table->boolean('overage_enabled')->default(false)->after('ram_overage_rate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cpu_overage_rate', 'ram_overage_rate', 'overage_enabled']);
        });
    }
};
