<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Add new foreign keys
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->onDelete('set null');
            $table->foreignId('reseller_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->foreignId('node_id')->nullable();

            // Service configuration
            $table->string('provisioning_driver_key')->nullable();
            $table->string('billing_cycle')->default('monthly')->change();
            $table->dateTime('next_due_date')->nullable()->change();
            $table->dateTime('suspend_date')->nullable();
            $table->dateTime('terminate_date')->nullable();

            // Rename and restructure
            $table->json('service_meta')->nullable();
            $table->string('external_reference')->nullable()->unique();
            $table->text('credentials')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeignKey(['order_item_id']);
            $table->dropForeignKey(['reseller_id']);
            $table->dropForeignKey(['invoice_id']);
            $table->dropColumn('order_item_id');
            $table->dropColumn('reseller_id');
            $table->dropColumn('invoice_id');
            $table->dropColumn('node_id');
            $table->dropColumn('provisioning_driver_key');
            $table->dropColumn('suspend_date');
            $table->dropColumn('terminate_date');
            $table->dropColumn('service_meta');
            $table->dropColumn('external_reference');
            $table->dropColumn('credentials');
        });
    }
};
