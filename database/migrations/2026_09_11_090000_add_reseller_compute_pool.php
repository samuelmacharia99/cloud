<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CPU and memory pool for reseller packages.
 *
 * Disk was the only pool a reseller package had, so application hosting plans
 * consumed node compute that nothing counted, priced or capped.
 *
 * Both pools default to zero, which means unmetered. Every package that exists
 * today keeps behaving exactly as it does now until an operator sets a number,
 * so no live reseller is refused an order the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            // decimal(8,2) matches container_deployments.cpu_limit exactly, so a
            // pool and the allocations measured against it share one precision.
            $table->decimal('cpu_pool_cores', 8, 2)->default(0)->after('disk_overage_rate');
            $table->unsignedInteger('memory_pool_mb')->default(0)->after('cpu_pool_cores');
        });

        Schema::table('reseller_disk_usage_snapshots', function (Blueprint $table) {
            // Compute history lives beside disk history rather than in a second
            // table: the grain is identical, one row per reseller per day, and a
            // separate table would duplicate the key, the cron and the rollup.
            // The table name is now narrower than what it holds; renaming it
            // would touch three services, a cron and four test files, and is
            // deliberately deferred rather than bundled into this change.
            //
            // Nullable rather than zero: null means "not collected", zero means
            // "collected, nothing allocated". Any future averaging must exclude
            // nulls, and that distinction cannot be recovered later.
            $table->decimal('cpu_cores_allocated', 12, 4)->nullable()->after('total_used_gb');
            $table->unsignedInteger('memory_mb_allocated')->nullable()->after('cpu_cores_allocated');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->dropColumn(['cpu_pool_cores', 'memory_pool_mb']);
        });

        Schema::table('reseller_disk_usage_snapshots', function (Blueprint $table) {
            $table->dropColumn(['cpu_cores_allocated', 'memory_mb_allocated']);
        });
    }
};
