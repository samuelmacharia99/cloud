<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerBackupService;

class BackupContainersCommand extends BaseCronCommand
{
    protected $signature = 'cron:backup-containers
        {--force : Force backup of all active containers regardless of last backup time}';

    protected $description = 'Create scheduled backups for active container services that haven\'t been backed up in 24 hours';

    protected function handleCron(): string
    {
        $backupService = new ContainerBackupService;
        $notificationService = app(NotificationService::class);
        $force = $this->option('force');

        $deployments = ContainerDeployment::with('service', 'node')
            ->where('status', 'running')
            ->get();

        if ($deployments->isEmpty()) {
            return 'No active container deployments found.';
        }

        $this->info("Found {$deployments->count()} active container deployments.");

        $backedUp = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($deployments as $deployment) {
            $service = $deployment->service;

            if (! $force) {
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

                $notificationService->notifyContainerBackupCompleted($service, $backup);

                $backedUp++;
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗ Failed</> {$service->id}: {$e->getMessage()}");

                $notificationService->notifyContainerBackupFailed($service, $e->getMessage());

                $failed++;
            }
        }

        return "Backup complete: {$backedUp} succeeded, {$skipped} skipped, {$failed} failed.";
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
