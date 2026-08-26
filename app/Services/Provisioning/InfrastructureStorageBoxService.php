<?php

namespace App\Services\Provisioning;

use App\Models\ContainerBackup;
use Illuminate\Support\Collection;

class InfrastructureStorageBoxService
{
    public function __construct(
        private HetznerStorageBoxClient $hetzner,
        private StorageBoxRetentionService $retention,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        $boxes = collect();

        if ($this->hetzner->isConfigured()) {
            $boxes->push($this->hetznerBox());
        }

        return $boxes;
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrFail(string $id): array
    {
        $box = $this->list()->firstWhere('id', $id);

        if ($box === null) {
            abort(404, 'Storage Box not found or not configured.');
        }

        return $this->enrichDetail($box);
    }

    public function refreshDiskUsage(string $id): void
    {
        $this->findOrFail($id);
        $this->hetzner->clearDiskUsageCache();
        $this->hetzner->diskUsage(true);
    }

    public function configuredCount(): int
    {
        return $this->list()->count();
    }

    /**
     * @param  array<string, mixed>  $box
     * @return array<string, mixed>
     */
    private function enrichDetail(array $box): array
    {
        $days = $this->retention->retentionDays();

        return array_merge($box, [
            'disk' => $this->hetzner->diskUsage(),
            'retention_days' => $days,
            'auto_purge_enabled' => $this->retention->autoPurgeEnabled(),
            'eligible_purge_count' => $this->retention->eligibleBackupCount($days),
            'eligible_purge_bytes' => $this->retention->eligibleBackupBytes($days),
            'show_url' => route('admin.storage-boxes.show', $box['id']),
            'refresh_url' => route('admin.storage-boxes.refresh-stats', $box['id']),
            'purge_url' => route('admin.storage-boxes.purge-retention', $box['id']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hetznerBox(): array
    {
        $config = $this->hetzner->configurationSummary();
        $stats = ContainerBackup::query()
            ->where('storage_driver', 'hetzner')
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as backup_count, COALESCE(SUM(size_bytes), 0) as total_bytes, MAX(completed_at) as last_backup_at')
            ->first();

        $backupCount = (int) ($stats->backup_count ?? 0);
        $backupBytes = (int) ($stats->total_bytes ?? 0);
        $lastBackupAt = $stats->last_backup_at ?? null;
        $disk = $this->hetzner->diskUsage();

        return [
            'id' => 'hetzner-primary',
            'provider' => 'hetzner',
            'type' => 'storage_box',
            'name' => $this->displayName($config),
            'host' => $config['host'],
            'port' => $config['port'],
            'username' => $config['username'],
            'base_path' => $config['base_path'],
            'is_active_driver' => $config['uses_hetzner'],
            'status' => $this->resolveStatus($config, $lastBackupAt),
            'backup_count' => $backupCount,
            'backup_bytes' => $backupBytes,
            'last_backup_at' => $lastBackupAt,
            'disk' => $disk,
            'settings_url' => route('admin.settings.index', ['tab' => 'provisioning']),
            'test_url' => route('admin.settings.test-hetzner-storage'),
            'show_url' => route('admin.storage-boxes.show', 'hetzner-primary'),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function displayName(array $config): string
    {
        $username = trim((string) ($config['username'] ?? ''));
        $host = trim((string) ($config['host'] ?? ''));

        if ($username !== '' && $host !== '') {
            return $username.' @ '.$host;
        }

        return $host !== '' ? $host : 'Hetzner Storage Box';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveStatus(array $config, mixed $lastBackupAt): string
    {
        if (! ($config['configured'] ?? false)) {
            return 'incomplete';
        }

        if ($lastBackupAt) {
            return 'online';
        }

        return ($config['uses_hetzner'] ?? false) ? 'configured' : 'standby';
    }
}
