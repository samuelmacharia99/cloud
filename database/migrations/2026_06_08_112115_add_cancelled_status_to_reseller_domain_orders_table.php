<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE reseller_domain_orders MODIFY COLUMN status ENUM('queued', 'pushed', 'completed', 'failed', 'expired', 'cancelled') NOT NULL DEFAULT 'queued'"
            );
        }

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        DB::table('reseller_domain_orders')->where('status', 'cancelled')->update(['status' => 'expired']);

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE reseller_domain_orders MODIFY COLUMN status ENUM('queued', 'pushed', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'queued'"
            );
        }
    }
};
