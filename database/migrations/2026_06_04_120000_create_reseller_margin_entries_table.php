<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_margin_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type', 32);
            $table->string('description');
            $table->decimal('retail_amount', 12, 2);
            $table->decimal('wholesale_amount', 12, 2)->default(0);
            $table->decimal('margin_amount', 12, 2);
            $table->timestamps();

            $table->index(['reseller_id', 'created_at']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_margin_entries');
    }
};
