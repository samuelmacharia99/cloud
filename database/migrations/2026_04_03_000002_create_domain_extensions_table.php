<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_extensions', function (Blueprint $table) {
            $table->id();
            $table->string('extension')->unique(); // .com, .co.ke, .org, etc
            $table->string('description')->nullable(); // "Commercial", "Kenya Country Code"
            $table->boolean('enabled')->default(true);
            $table->integer('registration_period_min')->default(1); // min years
            $table->integer('registration_period_max')->default(10); // max years
            $table->string('registrar')->default('internal'); // which registrar handles this
            $table->boolean('dns_management')->default(true);
            $table->boolean('auto_renewal')->default(true);
            $table->timestamps();

            $table->index('extension');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_extensions');
    }
};
