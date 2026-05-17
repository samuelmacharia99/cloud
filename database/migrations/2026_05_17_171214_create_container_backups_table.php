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
        Schema::create('container_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_deployment_id')->constrained('container_deployments')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('backup_name');
            $table->string('backup_path'); // Remote path on node
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'restoring', 'deleted'])->default('pending');
            $table->enum('type', ['manual', 'scheduled'])->default('manual');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('service_id');
            $table->index('node_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_backups');
    }
};
