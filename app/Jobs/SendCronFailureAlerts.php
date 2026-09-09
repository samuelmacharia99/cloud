<?php

namespace App\Jobs;

use App\Models\CronJob;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCronFailureAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $cronJobId)
    {
        $this->onQueue('default');
    }

    public function handle(TelegramMonitorBridge $telegram): void
    {
        $job = CronJob::query()->find($this->cronJobId);
        if (! $job) {
            return;
        }

        $telegram->systemAlert('Cron job failed', [
            'Job' => $job->name,
            'Command' => $job->command,
            'Schedule' => $job->schedule,
        ]);
    }
}
