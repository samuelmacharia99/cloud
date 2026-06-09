<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->unsignedInteger('max_services')->nullable()->after('storage_space');
            $table->unsignedInteger('disk_pool_gb')->default(0)->after('max_services');
            $table->decimal('disk_overage_rate', 10, 4)->nullable()->after('disk_pool_gb');
        });

        DB::table('reseller_packages')->update([
            'max_services' => DB::raw('storage_space'),
            'disk_pool_gb' => DB::raw('storage_space'),
        ]);

        Schema::table('reseller_products', function (Blueprint $table) {
            $table->json('resource_limits')->nullable()->after('direct_admin_package_name');
            $table->json('features')->nullable()->after('resource_limits');
            $table->unsignedBigInteger('container_template_id')->nullable()->after('product_id');
        });

        Schema::create('reseller_disk_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('directadmin_used_gb', 12, 4)->default(0);
            $table->decimal('container_used_gb', 12, 4)->default(0);
            $table->decimal('total_used_gb', 12, 4)->default(0);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['reseller_id', 'period_date']);
            $table->index('period_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_disk_usage_snapshots');

        Schema::table('reseller_products', function (Blueprint $table) {
            $table->dropColumn(['resource_limits', 'features', 'container_template_id']);
        });

        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->dropColumn(['max_services', 'disk_pool_gb', 'disk_overage_rate']);
        });
    }
};
