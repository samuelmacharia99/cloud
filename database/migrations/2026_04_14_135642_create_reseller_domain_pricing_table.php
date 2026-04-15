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
        Schema::create('reseller_domain_pricing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('domain_extension_id');
            $table->integer('period_years');
            $table->decimal('retail_price', 10, 2);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['reseller_id', 'domain_extension_id', 'period_years'], 'rdp_reseller_ext_period_unique');
            $table->foreign('reseller_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('domain_extension_id')->references('id')->on('domain_extensions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_domain_pricing');
    }
};
