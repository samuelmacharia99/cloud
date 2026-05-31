<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'product_type')) {
                $table->string('product_type', 50)->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('invoice_items', 'custom_options')) {
                $table->json('custom_options')->nullable()->after('amount');
            }
        });

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_domain_orders', 'customer_invoice_id')) {
                $table->unsignedBigInteger('customer_invoice_id')->nullable()->after('admin_invoice_id');
                $table->foreign('customer_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'wallet_amount_applied')) {
                $table->decimal('wallet_amount_applied', 12, 2)->default(0)->after('total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'wallet_amount_applied')) {
                $table->dropColumn('wallet_amount_applied');
            }
        });

        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            if (Schema::hasColumn('reseller_domain_orders', 'customer_invoice_id')) {
                $table->dropForeign(['customer_invoice_id']);
                $table->dropColumn('customer_invoice_id');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'custom_options')) {
                $table->dropColumn('custom_options');
            }
            if (Schema::hasColumn('invoice_items', 'product_type')) {
                $table->dropColumn('product_type');
            }
        });
    }
};
