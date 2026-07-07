<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_renewal_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('customer_id')->nullable()->after('reseller_id');
            $table->unsignedBigInteger('customer_invoice_id')->nullable()->after('invoice_id');
            $table->unsignedBigInteger('reseller_invoice_id')->nullable()->after('customer_invoice_id');
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('reseller_invoice_id');
            $table->decimal('wholesale_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('retail_amount', 12, 2)->nullable()->after('wholesale_amount');

            $table->foreign('reseller_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('customer_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('reseller_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('wallet_transaction_id')->references('id')->on('wallet_transactions')->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE domain_renewal_orders MODIFY status ENUM('pending', 'invoiced', 'queued', 'paid', 'pushed', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('domain_renewal_orders', function (Blueprint $table) {
                $table->string('status', 32)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('domain_renewal_orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['customer_invoice_id']);
            $table->dropForeign(['reseller_invoice_id']);
            $table->dropForeign(['wallet_transaction_id']);

            $table->dropColumn([
                'reseller_id',
                'customer_id',
                'customer_invoice_id',
                'reseller_invoice_id',
                'wallet_transaction_id',
                'wholesale_amount',
                'retail_amount',
            ]);
        });
    }
};
