<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ContainerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'container_deployment_id',
        'cpu_percentage',
        'memory_used_mb',
        'memory_limit_mb',
        'memory_percentage',
        'net_io_rx_bytes',
        'net_io_tx_bytes',
        'block_io_read_bytes',
        'block_io_write_bytes',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'cpu_percentage' => 'float',
        'memory_used_mb' => 'integer',
        'memory_limit_mb' => 'integer',
        'memory_percentage' => 'float',
        'net_io_rx_bytes' => 'integer',
        'net_io_tx_bytes' => 'integer',
        'block_io_read_bytes' => 'integer',
        'block_io_write_bytes' => 'integer',
    ];

    // Relationships
    public function deployment()
    {
        return $this->belongsTo(ContainerDeployment::class, 'container_deployment_id');
    }

    // Scopes
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('recorded_at', '>', now()->subHours($hours));
    }

    // Static helpers for billing calculations
    public static function averageCpuPercent(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $avg = self::where('container_deployment_id', $deployment->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->avg('cpu_percentage');

        return (float) ($avg ?? 0);
    }

    public static function averageMemoryMb(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $avg = self::where('container_deployment_id', $deployment->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->avg('memory_used_mb');

        return (float) ($avg ?? 0);
    }
}
