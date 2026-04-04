<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class CronJob extends Model
{
    protected $fillable = [
        'name', 'description', 'command', 'schedule',
        'enabled', 'last_ran_at', 'last_status', 'next_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_ran_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(CronJobLog::class);
    }

    public function latestLog(): HasOne
    {
        return $this->hasOne(CronJobLog::class)->latestOfMany('started_at');
    }

    public function calculateNextRunAt(): Carbon
    {
        $cron = new \Cron\CronExpression($this->schedule);
        return Carbon::instance($cron->getNextRunDate());
    }
}
