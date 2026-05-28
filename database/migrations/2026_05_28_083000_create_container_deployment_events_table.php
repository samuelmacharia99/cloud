<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_deployment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('container_deployment_id')->nullable()->constrained('container_deployments')->nullOnDelete();
            $table->string('event', 100);
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['service_id', 'recorded_at'], 'cde_service_recorded_idx');
            $table->index(['container_deployment_id', 'recorded_at'], 'cde_deploy_recorded_idx');
            $table->index('event', 'cde_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_deployment_events');
    }
};
