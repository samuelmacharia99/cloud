<?php

namespace App\Console\Commands;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Support\Carbon;

class BackupContainersCommand extends BaseCronCommand
{
    protected $signature = 'cron:backup-containers
        {--force : Force backup of all active containers regardless of last backup time}
        {--max-runtime= : Wall-clock seconds before stopping new backups (default from config)}';

    protected $description = 'Create scheduled backups for active container services that haven\'t been backed up in 24 hours';

    protected function handleCron(): string
    {
        $backupService = app(ContainerBackupService::class);
        $notificationService = app(NotificationService::class);
        $force = $this->option('force');
        $maxRuntime = $this->resolveMaxRuntimeSeconds();
        $deadline = $this->startTime->copy()->addSeconds($maxRuntime);

        $deployments = ContainerDeployment::with(['service', 'node'])
            ->where('status', 'running')
            ->get()
            ->filter(fn (ContainerDeployment $deployment) => $deployment->service && $deployment->node)
            ->sortBy(function (ContainerDeployment $deployment) {
                $last = $deployment->service->containerBackups()
                    ->whereIn('status', ['completed', 'restoring'])
                    ->orderByDesc('completed_at')
                    ->value('completed_at');

                return $last ? Carbon::parse($last)->timestamp : 0;
            })
            ->values();

        if ($deployments->isEmpty()) {
            return 'No active container deployments found.';
        }

        $this->info("Found {$deployments->count()} active container deployments (budget {$maxRuntime}s).");

        $backedUp = 0;
        $skipped = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($deployments as $deployment) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                $remaining = $deployments->count() - ($backedUp + $skipped + $failed + $deferred);
                $deferred += max(0, $remaining);
                $this->warn("Runtime budget exhausted; deferring {$remaining} remaining container(s) to the next run.");

                break;
            }

            $service = $deployment->service;

            if (ContainerBackup::query()
                ->where('service_id', $service->id)
                ->whereIn('status', ['pending', 'running'])
                ->exists()) {
                $this->line("  <fg=yellow>Skipped</> {$service->id}: backup already in progress");
                $skipped++;

                continue;
            }

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

                $this->line("  <fg=green>✓ Completed</> {$backup->backup_name} ({$this->formatBytes((int) $backup->size_bytes)})");

                $notificationService->notifyContainerBackupCompleted($service, $backup);

                $backedUp++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>✗ Failed</> {$service->id}: {$e->getMessage()}");

                $notificationService->notifyContainerBackupFailed($service, $e->getMessage());

                $failed++;
            }
        }

        $message = "Backup complete: {$backedUp} succeeded, {$skipped} skipped, {$failed} failed, {$deferred} deferred.";
        if ($failed > 0) {
            throw new \RuntimeException($message);
        }

        return $message;
    }

    private function resolveMaxRuntimeSeconds(): int
    {
        $option = $this->option('max-runtime');
        if ($option !== null && $option !== '') {
            return max(1, (int) $option);
        }

        return max(60, (int) config('cron.backup_containers.max_runtime_seconds', 12600));
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
