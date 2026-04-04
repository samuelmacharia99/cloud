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
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('command')->unique();
            $table->string('schedule');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_ran_at')->nullable();
            $table->enum('last_status', ['success', 'failed', 'running'])->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
