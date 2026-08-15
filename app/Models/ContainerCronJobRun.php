<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerCronJobRun extends Model
{
    protected $fillable = [
        'container_cron_job_id',
        'attempt_uuid',
        'scheduled_for',
        'status',
        'output',
        'exception',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(ContainerCronJob::class, 'container_cron_job_id');
    }
}
