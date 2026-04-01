<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'suspended', 'terminated', 'cancelled'])->default('active');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi-annual', 'annual'])->default('monthly');
            $table->date('next_due_date');
            $table->date('termination_date')->nullable();
            $table->json('custom_fields')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('product_id');
            $table->index('status');
            $table->index('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
