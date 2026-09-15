<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reseller package is sold by resources, not by head count: CPU, RAM, disk
 * and bandwidth pools with an overage rate each. Customer and service counts
 * become optional caps (0 = unlimited). Backups are part of the package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->unsignedInteger('bandwidth_pool_gb')->default(0)->after('memory_pool_mb');
            $table->decimal('cpu_overage_rate', 10, 4)->nullable()->after('bandwidth_pool_gb');
            $table->decimal('memory_overage_rate', 10, 4)->nullable()->after('cpu_overage_rate');
            $table->decimal('bandwidth_overage_rate', 10, 4)->nullable()->after('memory_overage_rate');
            $table->boolean('backups_included')->default(true)->after('bandwidth_overage_rate');
            $table->integer('max_users')->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->dropColumn(['bandwidth_pool_gb', 'cpu_overage_rate', 'memory_overage_rate', 'bandwidth_overage_rate', 'backups_included']);
        });
    }
};
