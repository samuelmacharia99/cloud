<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // ISO 4217 code (USD, EUR, GBP, KES, etc.)
            $table->string('name'); // e.g., "United States Dollar"
            $table->string('symbol'); // e.g., "$", "€", "£", "KES"
            $table->decimal('exchange_rate', 15, 6)->default(1); // Rate relative to base currency (KES)
            $table->boolean('is_active')->default(true);
            $table->timestamp('rate_updated_at')->nullable();
            $table->integer('order')->default(0); // Display order
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
