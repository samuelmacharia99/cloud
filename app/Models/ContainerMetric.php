<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ContainerMetric extends Model
{
    public $timestamps = false;

    public const SAMPLE_USAGE = 'usage';

    public const SAMPLE_DOWNTIME = 'downtime';

    protected $fillable = [
        'container_deployment_id',
        'sample_type',
        'cpu_percentage',
        'memory_used_mb',
        'memory_limit_mb',
        'memory_percentage',
        'net_io_rx_bytes',
        'net_io_tx_bytes',
        'block_io_read_bytes',
        'block_io_write_bytes',
        'disk_used_gb',
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
        'disk_used_gb' => 'float',
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
    public function scopeUsageSamples($query)
    {
        return $query->where('sample_type', self::SAMPLE_USAGE);
    }

    public function scopeInBillingPeriod($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    public static function averageCpuPercent(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $avg = self::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->inBillingPeriod($from, $to)
            ->avg('cpu_percentage');

        return (float) ($avg ?? 0);
    }

    public static function peakMemoryMb(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $peak = self::query()
            ->where('container_deployment_id', $deployment->id)
            ->inBillingPeriod($from, $to)
            ->max('memory_used_mb');

        return (float) ($peak ?? 0);
    }

    public static function averageDiskUsedGb(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $avg = self::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->inBillingPeriod($from, $to)
            ->avg('disk_used_gb');

        return (float) ($avg ?? 0);
    }
}
