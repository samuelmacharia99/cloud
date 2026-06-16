<?php

namespace App\Models;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function scheduleTimezone(): string
    {
        return (string) Setting::getValue('cron_timezone', config('app.timezone'));
    }

    public function calculateNextRunAt(?Carbon $from = null): Carbon
    {
        $cron = new CronExpression($this->schedule);
        $from = $from ?? now();

        return Carbon::instance(
            $cron->getNextRunDate($from->toDateTimeString(), 0, false, $this->scheduleTimezone())
        );
    }

    public function refreshNextRunAt(): void
    {
        $this->forceFill([
            'next_run_at' => $this->calculateNextRunAt(),
        ])->save();
    }

    public function getResolvedNextRunAtAttribute(): Carbon
    {
        return $this->next_run_at ?? $this->calculateNextRunAt();
    }
}
