<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_deployments', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->setNullOnDelete();

            // Container identification
            $table->string('container_name')->unique(); // talksasa-{service_id}-{random}
            $table->enum('status', ['deploying', 'running', 'stopped', 'failed', 'terminating', 'terminated'])->default('deploying');

            // Configuration & deployment
            $table->longText('docker_compose_content')->nullable(); // full rendered docker-compose.yml for audit trail
            $table->unsignedSmallInteger('assigned_port')->nullable(); // 30000-40000
            $table->string('internal_ip')->nullable(); // docker network IP

            // Networking & access
            $table->string('domain')->nullable(); // assigned subdomain or custom domain

            // Metadata
            $table->json('env_values')->nullable(); // customer-provided env values (unencrypted, for non-sensitive data only)

            // Monitoring
            $table->timestamp('last_status_check_at')->nullable();
            $table->text('last_status_check_output')->nullable(); // raw docker ps JSON output

            // Lifecycle timestamps
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('service_id');
            $table->index('node_id');
            $table->index('status');
            $table->index(['node_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_deployments');
    }
};
