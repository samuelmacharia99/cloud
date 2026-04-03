<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'type',
        'status',
        'cpu_cores',
        'ram_gb',
        'storage_gb',
        'cpu_used',
        'ram_used_gb',
        'storage_used_gb',
        'ssh_port',
        'api_url',
        'api_token',
        'verify_ssl',
        'region',
        'datacenter',
        'description',
        'container_count',
        'last_heartbeat_at',
        'last_health_check_at',
        'is_active',
    ];

    protected $casts = [
        'cpu_cores' => 'integer',
        'ram_gb' => 'integer',
        'storage_gb' => 'integer',
        'cpu_used' => 'integer',
        'ram_used_gb' => 'integer',
        'storage_used_gb' => 'integer',
        'container_count' => 'integer',
        'verify_ssl' => 'boolean',
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function services()
    {
        return $this->hasMany(Service::class, 'node_id');
    }

    public function monitoring()
    {
        return $this->hasMany(NodeMonitoring::class, 'node_id');
    }

    public function latestMonitoring()
    {
        return $this->hasOne(NodeMonitoring::class, 'node_id')->latest('recorded_at');
    }

    // Helper Methods
    public function isMonitored(): bool
    {
        return in_array($this->type, ['container_host', 'database_server']);
    }

    public function isHealthy(): bool
    {
        return $this->status === 'online' && $this->is_active;
    }

    public function isOffline(): bool
    {
        return $this->status === 'offline';
    }

    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }

    public function getAvailableCpuCores(): int
    {
        return max(0, $this->cpu_cores - ($this->cpu_used * $this->cpu_cores / 100));
    }

    public function getAvailableRamGb(): int
    {
        return max(0, $this->ram_gb - $this->ram_used_gb);
    }

    public function getAvailableStorageGb(): int
    {
        return max(0, $this->storage_gb - $this->storage_used_gb);
    }

    public function getCpuUsagePercentage(): int
    {
        if ($this->cpu_cores === 0) {
            return 0;
        }
        return min(100, $this->cpu_used);
    }

    public function getRamUsagePercentage(): int
    {
        if ($this->ram_gb === 0) {
            return 0;
        }
        return (int) (($this->ram_used_gb / $this->ram_gb) * 100);
    }

    public function getStorageUsagePercentage(): int
    {
        if ($this->storage_gb === 0) {
            return 0;
        }
        return (int) (($this->storage_used_gb / $this->storage_gb) * 100);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'online' => 'emerald',
            'offline' => 'red',
            'degraded' => 'amber',
            'maintenance' => 'blue',
            default => 'slate',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'dedicated_server' => 'Dedicated Server',
            'container_host' => 'Container Host',
            'load_balancer' => 'Load Balancer',
            'database_server' => 'Database Server',
            default => 'Unknown',
        };
    }

    public function updateUtilization(int $cpuUsed, int $ramUsedGb, int $storageUsedGb): void
    {
        $this->update([
            'cpu_used' => $cpuUsed,
            'ram_used_gb' => $ramUsedGb,
            'storage_used_gb' => $storageUsedGb,
            'last_health_check_at' => now(),
        ]);
    }

    public function recordHeartbeat(): void
    {
        $this->update([
            'last_heartbeat_at' => now(),
        ]);
    }
}
