<?php

use App\Models\CronJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        $job = CronJob::query()->firstOrNew(['command' => 'cron:refresh-project-consumption']);
        $job->fill([
            'name' => 'Refresh Project Consumption',
            'description' => 'Averages last-6-hour container metrics per project and stores used-vs-included plan consumption.',
            'schedule' => '10 */6 * * *',
        ]);
        if (! $job->exists) {
            $job->enabled = true;
        }
        $job->save();
        $job->refreshNextRunAt();
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        CronJob::query()->where('command', 'cron:refresh-project-consumption')->delete();
    }
};
