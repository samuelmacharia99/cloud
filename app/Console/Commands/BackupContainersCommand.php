<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Console\Command;

class BackupContainersCommand extends Command
{
    protected $signature = 'cron:backup-containers
        {--force : Force backup of all active containers regardless of last backup time}';

    protected $description = 'Create scheduled backups for active container services that haven\'t been backed up in 24 hours';

    public function handle()
    {
        $backupService = new ContainerBackupService();
        $notificationService = app(NotificationService::class);
        $force = $this->option('force');

        // Find all active container deployments
        $deployments = ContainerDeployment::with('service', 'node')
            ->where('status', 'running')
            ->get();

        if ($deployments->isEmpty()) {
            $this->info('No active container deployments found.');
            return;
        }

        $this->info("Found {$deployments->count()} active container deployments.");

        $backed_up = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($deployments as $deployment) {
            $service = $deployment->service;

            // Skip if not forcibly backing up and was backed up in last 24 hours
            if (!$force) {
                $lastBackup = $service->containerBackups()
                    ->whereIn('status', ['completed', 'restoring'])
                    ->orderByDesc('completed_at')
                    ->first();

                if ($lastBackup && $lastBackup->completed_at?->diffInHours(now()) < 24) {
                    $this->line("  <fg=yellow>Skipped</> {$service->id}: Last backup {$lastBackup->completed_at->diffForHumans()}");
                    $skipped++;
                    continue;
                }
            }

            try {
                $this->line("  <fg=blue>Backing up</> service {$service->id}...");

                $backup = $backupService->createBackup($service, 'scheduled');

                $this->line("  <fg=green>✓ Completed</> {$backup->backup_name} ({$this->formatBytes($backup->size_bytes)})");

                // Send completion notification
                $notificationService->notifyContainerBackupCompleted($service, $backup);

                $backed_up++;
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗ Failed</> {$service->id}: {$e->getMessage()}");

                // Send failure notification
                $notificationService->notifyContainerBackupFailed($service, $e->getMessage());

                $failed++;
            }
        }

        $this->info("Backup complete: {$backed_up} succeeded, {$skipped} skipped, {$failed} failed.");
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
