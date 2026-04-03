<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_extension_id')->constrained('domain_extensions')->cascadeOnDelete();
            $table->integer('period_years'); // 1, 2, 3, 5, 10
            $table->enum('tier', ['retail', 'wholesale']); // retail = regular customer, wholesale = reseller
            $table->decimal('price', 10, 2); // annual rate or total for period
            $table->decimal('setup_fee', 10, 2)->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['domain_extension_id', 'period_years', 'tier']);
            $table->index(['domain_extension_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_pricing');
    }
};
