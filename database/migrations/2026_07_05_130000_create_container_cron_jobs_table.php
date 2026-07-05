<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('name');
            $table->string('schedule', 100);
            $table->text('command');
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_output')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
            $table->index('service_id');
        });

        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        $updated = DB::table('cron_jobs')
            ->where('command', 'cron:run-container-jobs')
            ->update([
                'name' => 'Run Container Cron Jobs',
                'description' => 'Executes customer-defined scheduled commands inside running container services via docker exec.',
                'schedule' => '* * * * *',
                'enabled' => true,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('cron_jobs')->insert([
                'name' => 'Run Container Cron Jobs',
                'description' => 'Executes customer-defined scheduled commands inside running container services via docker exec.',
                'command' => 'cron:run-container-jobs',
                'schedule' => '* * * * *',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('container_cron_jobs');

        if (Schema::hasTable('cron_jobs')) {
            DB::table('cron_jobs')->where('command', 'cron:run-container-jobs')->delete();
        }
    }
};
