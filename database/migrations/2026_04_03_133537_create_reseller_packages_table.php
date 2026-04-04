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
        Schema::create('reseller_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., "Starter", "Professional", "Enterprise"
            $table->text('description')->nullable();
            $table->enum('billing_cycle', ['monthly', 'annually']);
            $table->integer('storage_space'); // in GB
            $table->integer('max_users');
            $table->decimal('price', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('billing_cycle');
            $table->index('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_packages');
    }
};
