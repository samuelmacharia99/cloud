<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_cron_jobs', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('enabled');
            $table->boolean('paused_by_system')->default(false)->after('is_system');
        });

        DB::table('container_cron_jobs')
            ->where(function ($query) {
                $query->where('name', 'WordPress system cron')
                    ->orWhere('command', 'php /var/www/html/wp-cron.php');
            })
            ->update(['is_system' => true]);

        Schema::create('container_cron_job_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_cron_job_id')
                ->constrained('container_cron_jobs')
                ->cascadeOnDelete();
            $table->uuid('attempt_uuid')->unique();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('status', 20);
            $table->text('output')->nullable();
            $table->text('exception')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['container_cron_job_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_cron_job_runs');

        Schema::table('container_cron_jobs', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'paused_by_system']);
        });
    }
};
