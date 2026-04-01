<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('service_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
