<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_terminal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('container_terminal_sessions')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->text('command');
            $table->text('sanitized_command')->nullable();
            $table->longText('output')->nullable();
            $table->tinyInteger('exit_code')->nullable();
            $table->unsignedInteger('execution_ms')->nullable();
            $table->string('cwd', 500);
            $table->string('ip_address', 45);
            $table->boolean('is_blocked')->default(false);
            $table->string('block_reason', 255)->nullable();
            $table->timestamp('created_at');

            $table->index('session_id');
            $table->index(['user_id', 'created_at']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_terminal_logs');
    }
};
