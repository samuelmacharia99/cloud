<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cron_job_id')->constrained('cron_jobs')->cascadeOnDelete();
            $table->enum('status', ['running', 'success', 'failed']);
            $table->longText('output')->nullable();
            $table->text('exception')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index('cron_job_id');
            $table->index('status');
            $table->index('started_at');
            $table->index(['cron_job_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_job_logs');
    }
};
