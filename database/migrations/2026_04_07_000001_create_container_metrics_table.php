<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_metrics', function (Blueprint $table) {
            $table->id();

            // Reference to deployment
            $table->foreignId('container_deployment_id')->constrained('container_deployments')->cascadeOnDelete();

            // Resource metrics
            $table->decimal('cpu_percentage', 5, 2)->default(0); // 0.00–400.00 for multi-core
            $table->unsignedInteger('memory_used_mb')->default(0);
            $table->unsignedInteger('memory_limit_mb')->default(0);
            $table->decimal('memory_percentage', 5, 2)->default(0);

            // Network I/O (cumulative, reset on container restart)
            $table->unsignedBigInteger('net_io_rx_bytes')->default(0); // received
            $table->unsignedBigInteger('net_io_tx_bytes')->default(0); // transmitted

            // Block I/O (cumulative, reset on container restart)
            $table->unsignedBigInteger('block_io_read_bytes')->default(0);
            $table->unsignedBigInteger('block_io_write_bytes')->default(0);

            // Timestamp (no soft deletes, no updated_at)
            $table->timestamp('recorded_at')->useCurrent();

            // Indexes
            $table->index(['container_deployment_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_metrics');
    }
};
