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
        Schema::create('container_file_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('deployment_id')->constrained('container_deployments')->onDelete('cascade');
            $table->enum('action', ['list', 'download', 'upload', 'delete', 'mkdir']);
            $table->string('path', 500);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45);
            $table->timestamp('created_at')->useCurrent();
            $table->index('service_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_file_audit_logs');
    }
};
