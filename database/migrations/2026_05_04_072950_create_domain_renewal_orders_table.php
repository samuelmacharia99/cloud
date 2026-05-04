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
        Schema::create('domain_renewal_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domain_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('admin_order_id')->nullable();
            $table->unsignedBigInteger('admin_invoice_id')->nullable();
            $table->tinyInteger('years')->default(1);
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'invoiced', 'paid', 'pushed', 'completed', 'failed', 'expired'])->default('pending');
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->tinyInteger('retry_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('domain_id')->references('id')->on('domains')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');
            $table->foreign('admin_order_id')->references('id')->on('orders')->onDelete('set null');
            $table->foreign('admin_invoice_id')->references('id')->on('invoices')->onDelete('set null');

            $table->index(['domain_id', 'user_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_renewal_orders');
    }
};
