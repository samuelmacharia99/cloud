<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeMonitoring extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'node_id',
        'uptime_percentage',
        'ram_used_gb',
        'ram_total_gb',
        'storage_used_gb',
        'storage_total_gb',
        'cpu_percentage',
        'recorded_at',
    ];

    protected $casts = [
        'uptime_percentage' => 'integer',
        'ram_used_gb' => 'integer',
        'ram_total_gb' => 'integer',
        'storage_used_gb' => 'integer',
        'storage_total_gb' => 'integer',
        'cpu_percentage' => 'integer',
        'recorded_at' => 'datetime',
    ];

    // Relationships
    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    // Helper Methods
    public function getRamUsagePercentage(): int
    {
        if ($this->ram_total_gb === 0) {
            return 0;
        }
        return (int) (($this->ram_used_gb / $this->ram_total_gb) * 100);
    }

    public function getStorageUsagePercentage(): int
    {
        if ($this->storage_total_gb === 0) {
            return 0;
        }
        return (int) (($this->storage_used_gb / $this->storage_total_gb) * 100);
    }

    public function isHealthy(): bool
    {
        $ram_pct = $this->getRamUsagePercentage();
        $storage_pct = $this->getStorageUsagePercentage();
        $uptime = $this->uptime_percentage;

        // Thresholds
        return $ram_pct <= 85 && $storage_pct <= 90 && $uptime >= 95;
    }

    public function isDegraded(): bool
    {
        $ram_pct = $this->getRamUsagePercentage();
        $storage_pct = $this->getStorageUsagePercentage();
        $uptime = $this->uptime_percentage;

        // Warning thresholds
        return $ram_pct > 85 || $storage_pct > 90 || $uptime < 95;
    }

    public function getAlert(): ?string
    {
        $ram_pct = $this->getRamUsagePercentage();
        $storage_pct = $this->getStorageUsagePercentage();
        $uptime = $this->uptime_percentage;

        if ($ram_pct > 90) {
            return "RAM critically high ({$ram_pct}%)";
        }
        if ($storage_pct > 95) {
            return "Storage critically high ({$storage_pct}%)";
        }
        if ($uptime < 90) {
            return "Uptime critically low ({$uptime}%)";
        }
        if ($ram_pct > 85) {
            return "RAM warning ({$ram_pct}%)";
        }
        if ($storage_pct > 90) {
            return "Storage warning ({$storage_pct}%)";
        }
        if ($uptime < 95) {
            return "Uptime warning ({$uptime}%)";
        }

        return null;
    }
}
