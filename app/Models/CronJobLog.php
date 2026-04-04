<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJobLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cron_job_id', 'status', 'output', 'exception',
        'duration_ms', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(CronJob::class);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_ms) {
            return 'N/A';
        }

        return $this->duration_ms >= 1000
            ? round($this->duration_ms / 1000, 2) . 's'
            : $this->duration_ms . 'ms';
    }
}
