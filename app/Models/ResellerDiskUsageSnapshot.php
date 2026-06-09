<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerDiskUsageSnapshot extends Model
{
    protected $fillable = [
        'reseller_id',
        'period_date',
        'directadmin_used_gb',
        'container_used_gb',
        'total_used_gb',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'directadmin_used_gb' => 'float',
            'container_used_gb' => 'float',
            'total_used_gb' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
