<?php

namespace App\Services\Provisioning;

use App\Models\ContainerBackup;
use App\Models\User;
use App\Services\AdminActivityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class StorageBoxRetentionService
{
    public function __construct(
        private ContainerBackupService $backupService,
        private HetznerStorageBoxClient $hetzner,
    ) {}

    public function retentionDays(): int
    {
        $days = (int) (setting('hetzner_storage_retention_days') ?: 30);

        return max(1, min(3650, $days));
    }

    public function autoPurgeEnabled(): bool
    {
        return in_array(setting('hetzner_storage_auto_purge', '0'), ['1', 'true', true], true);
    }

    public function eligibleBackupCount(?int $days = null): int
    {
        return $this->eligibleBackupsQuery($days ?? $this->retentionDays())->count();
    }

    public function eligibleBackupBytes(?int $days = null): int
    {
        return (int) $this->eligibleBackupsQuery($days ?? $this->retentionDays())
            ->sum('size_bytes');
    }

    /**
     * @return array{purged_count: int, freed_bytes: int, days: int, errors: array<int, array{backup_id: int, message: string}>}
     */
    public function purgeOlderThan(int $days, ?User $admin = null): array
    {
        if (! $this->hetzner->isConfigured()) {
            throw new \RuntimeException('Hetzner Storage Box is not configured.');
        }

        $days = max(1, min(3650, $days));
        $backups = $this->eligibleBackupsQuery($days)->get();

        $purgedCount = 0;
        $freedBytes = 0;
        $errors = [];

        foreach ($backups as $backup) {
            try {
                $freedBytes += (int) $backup->size_bytes;
                $this->backupService->purgeBackup($backup);
                $purgedCount++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'backup_id' => $backup->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($admin) {
            AdminActivityService::log(
                'storage_box.retention_purge',
                "Purged {$purgedCount} Storage Box backup archive(s) older than {$days} day(s).",
                null,
                [
                    'days' => $days,
                    'purged_count' => $purgedCount,
                    'freed_bytes' => $freedBytes,
                    'errors' => $errors,
                ],
            );
        }

        return [
            'purged_count' => $purgedCount,
            'freed_bytes' => $freedBytes,
            'days' => $days,
            'errors' => $errors,
        ];
    }

    /**
     * @return Builder<ContainerBackup>
     */
    private function eligibleBackupsQuery(int $days)
    {
        $cutoff = Carbon::now()->subDays($days);

        return ContainerBackup::query()
            ->where('storage_driver', 'hetzner')
            ->where('status', 'completed')
            ->where(function ($query) use ($cutoff) {
                $query->where('completed_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('completed_at')
                            ->where('created_at', '<', $cutoff);
                    });
            });
    }
}
