<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('deposit','domain_debit','subscription_debit','refund','adjustment') NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_transactions')
                ->where('type', 'subscription_debit')
                ->update(['type' => 'domain_debit']);

            DB::statement(
                "ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('deposit','domain_debit','refund','adjustment') NOT NULL"
            );
        }
    }
};
