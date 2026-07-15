<?php

namespace App\Jobs;

use App\Models\ContainerBackup;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateContainerBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Large WordPress volumes + Hetzner offload can take a long time. */
    public int $timeout = 7200;

    public function __construct(
        public int $backupId,
    ) {}

    public function handle(ContainerBackupService $backups, NotificationService $notifications): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $backup = ContainerBackup::query()->find($this->backupId);
        if (! $backup) {
            return;
        }

        $service = Service::with('containerDeployment.node')->find($backup->service_id);
        if (! $service) {
            $backup->update([
                'status' => 'failed',
                'error_message' => 'Service not found for queued backup.',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $completed = $backups->runQueuedBackup($backup);
            $notifications->notifyContainerBackupCompleted($service, $completed);
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($e->getMessage());
            $notifications->notifyContainerBackupFailed($service, $e->getMessage());
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('CreateContainerBackupJob failed', [
            'backup_id' => $this->backupId,
            'error' => $e?->getMessage(),
        ]);

        $this->markFailed($e?->getMessage() ?? 'Backup job failed');

        $backup = ContainerBackup::query()->find($this->backupId);
        $service = $backup?->service_id ? Service::find($backup->service_id) : null;
        if ($service) {
            app(NotificationService::class)->notifyContainerBackupFailed(
                $service,
                $e?->getMessage() ?? 'Backup job failed'
            );
        }
    }

    private function markFailed(string $message): void
    {
        $backup = ContainerBackup::query()->find($this->backupId);
        if (! $backup || in_array($backup->status, ['completed', 'deleted'], true)) {
            return;
        }

        $backup->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
