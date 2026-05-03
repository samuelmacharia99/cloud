<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reseller_domain_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('domain_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('admin_order_id')->nullable();
            $table->unsignedBigInteger('admin_invoice_id')->nullable();
            $table->string('domain_name', 253);
            $table->string('extension', 20);
            $table->tinyInteger('years')->default(1);
            $table->decimal('wholesale_amount', 10, 2);
            $table->decimal('retail_amount', 10, 2);
            $table->enum('status', ['queued', 'pushed', 'completed', 'failed', 'expired'])->default('queued');
            $table->enum('push_mode', ['auto', 'manual'])->default('auto');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->tinyInteger('retry_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('reseller_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('domain_id')->references('id')->on('domains')->onDelete('set null');
            $table->foreign('wallet_transaction_id')->references('id')->on('wallet_transactions')->onDelete('set null');
            $table->foreign('admin_order_id')->references('id')->on('orders')->onDelete('set null');
            $table->foreign('admin_invoice_id')->references('id')->on('invoices')->onDelete('set null');
            $table->index(['reseller_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_domain_orders');
    }
};
