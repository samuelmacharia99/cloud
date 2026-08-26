<?php

namespace App\Services\Provisioning;

use App\Models\ContainerBackup;
use Illuminate\Support\Collection;

class InfrastructureStorageBoxService
{
    public function __construct(
        private HetznerStorageBoxClient $hetzner,
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

    public function configuredCount(): int
    {
        return $this->list()->count();
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
            'settings_url' => route('admin.settings.index', ['tab' => 'provisioning']),
            'test_url' => route('admin.settings.test-hetzner-storage'),
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
