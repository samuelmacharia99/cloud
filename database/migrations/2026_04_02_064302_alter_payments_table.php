<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (Schema::hasColumn('payments', 'gateway') && ! Schema::hasColumn('payments', 'payment_method')) {
            Schema::table('payments', fn (Blueprint $table) => $table->renameColumn('gateway', 'payment_method'));
        }

        if (Schema::hasColumn('payments', 'transaction_id') && ! Schema::hasColumn('payments', 'transaction_reference')) {
            Schema::table('payments', fn (Blueprint $table) => $table->renameColumn('transaction_id', 'transaction_reference'));
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'currency')) {
                $table->string('currency', 3)->default('KES')->after('amount');
            }
            if (! Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('status');
            }
        });

        // Keep existing payment rows. Later corrective migrations widen payment_purpose;
        // changing these columns in place avoids the historical drop-and-recreate data loss.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->string('transaction_reference')->nullable()->change();
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Reverting would either drop payment history or
        // reject valid null-invoice/reversed records introduced after this migration.
    }
};
