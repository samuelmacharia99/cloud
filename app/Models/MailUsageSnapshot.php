<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailUsageSnapshot extends Model
{
    protected $fillable = [
        'service_id',
        'mailbox_count',
        'alias_count',
        'quota_used_mb',
        'quota_limit_mb',
        'sampled_at',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'mailbox_count' => 'integer',
        'alias_count' => 'integer',
        'quota_used_mb' => 'integer',
        'quota_limit_mb' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public static function peakMailboxCount(Service $service, $from, $to): int
    {
        return (int) static::query()
            ->where('service_id', $service->id)
            ->whereBetween('sampled_at', [$from, $to])
            ->max('mailbox_count');
    }
}
