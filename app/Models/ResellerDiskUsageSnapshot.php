<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per reseller per day. Despite the name it now also carries the
 * compute allocated that day; see the 2026_09_11 compute pool migration for why
 * the table was extended rather than duplicated.
 */
class ResellerDiskUsageSnapshot extends Model
{
    protected $fillable = [
        'reseller_id',
        'period_date',
        'directadmin_used_gb',
        'container_used_gb',
        'total_used_gb',
        'cpu_cores_allocated',
        'memory_mb_allocated',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'directadmin_used_gb' => 'float',
            'container_used_gb' => 'float',
            'total_used_gb' => 'float',
            'cpu_cores_allocated' => 'float',
            'memory_mb_allocated' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
