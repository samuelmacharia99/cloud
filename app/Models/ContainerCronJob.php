<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerCronJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'name',
        'schedule',
        'command',
        'enabled',
        'is_system',
        'paused_by_system',
        'next_run_at',
        'last_run_at',
        'last_status',
        'last_output',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_system' => 'boolean',
        'paused_by_system' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContainerCronJobRun::class);
    }
}
