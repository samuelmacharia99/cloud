<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name'); // "Production Server 1", "Container Host 01"
            $table->string('hostname')->unique(); // server.example.com
            $table->string('ip_address')->unique(); // 192.168.1.10

            // Type & Status
            $table->enum('type', ['dedicated_server', 'container_host', 'load_balancer', 'database_server'])->default('dedicated_server');
            $table->enum('status', ['online', 'offline', 'degraded', 'maintenance'])->default('offline');

            // Hardware Specs
            $table->integer('cpu_cores')->default(0); // Number of CPU cores
            $table->integer('ram_gb')->default(0); // RAM in GB
            $table->integer('storage_gb')->default(0); // Storage in GB

            // Usage Tracking (updated periodically)
            $table->integer('cpu_used')->default(0); // CPU % used
            $table->integer('ram_used_gb')->default(0); // RAM used in GB
            $table->integer('storage_used_gb')->default(0); // Storage used in GB

            // Connection Details
            $table->string('ssh_port')->default('22');
            $table->string('api_url')->nullable(); // API endpoint for node
            $table->string('api_token')->nullable(); // Authentication token
            $table->boolean('verify_ssl')->default(true);

            // Location & Configuration
            $table->string('region')->nullable(); // us-east, eu-west, ap-south, etc
            $table->string('datacenter')->nullable(); // Specific datacenter name
            $table->text('description')->nullable();

            // Tracking
            $table->integer('container_count')->default(0); // Number of active containers/services
            $table->timestamp('last_heartbeat_at')->nullable(); // Last connection check
            $table->timestamp('last_health_check_at')->nullable(); // Last status check
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes for common queries
            $table->index('type');
            $table->index('status');
            $table->index('region');
            $table->index('is_active');
            $table->index(['status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
