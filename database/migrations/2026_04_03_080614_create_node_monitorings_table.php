<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_monitorings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();

            // Uptime tracking
            $table->integer('uptime_percentage')->default(100); // 0-100%

            // RAM metrics
            $table->integer('ram_used_gb');
            $table->integer('ram_total_gb');

            // Storage metrics
            $table->integer('storage_used_gb');
            $table->integer('storage_total_gb');

            // CPU metrics
            $table->integer('cpu_percentage')->nullable();

            // Timestamps
            $table->dateTime('recorded_at')->useCurrent();
            $table->index('node_id');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_monitorings');
    }
};
