<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('deployment_id')->constrained('container_deployments')->onDelete('cascade');
            $table->string('container_name', 200);
            $table->string('cwd', 500)->default('/');
            $table->json('command_history')->nullable();
            $table->enum('status', ['active', 'closed', 'expired'])->default('active');
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('command_count')->default(0);
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('hard_expires_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index('user_id');
            $table->index('token');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_terminal_sessions');
    }
};
