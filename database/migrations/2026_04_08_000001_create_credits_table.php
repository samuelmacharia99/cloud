<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('source'); // 'overpayment', 'refund', 'admin', 'promotion'
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'applied', 'expired', 'refunded'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('source');
        });

        // Create credit applications table to track which credits are applied to which invoices
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('credits')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount_applied', 12, 2);
            $table->timestamps();

            // Indexes
            $table->unique(['credit_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
        Schema::dropIfExists('credits');
    }
};
