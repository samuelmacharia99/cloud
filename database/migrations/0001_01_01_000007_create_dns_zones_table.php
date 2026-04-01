<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('domain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
