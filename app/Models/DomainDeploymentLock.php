<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainDeploymentLock extends Model
{
    public const STATUS_LOCKED = 'locked';

    public const STATUS_COOLDOWN = 'cooldown';

    protected $fillable = [
        'fqdn',
        'user_id',
        'service_id',
        'status',
        'locked_at',
        'cool_down_until',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'cool_down_until' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isBlocking(): bool
    {
        if ($this->status === self::STATUS_LOCKED) {
            return true;
        }

        if ($this->status === self::STATUS_COOLDOWN && $this->cool_down_until) {
            return $this->cool_down_until->isFuture();
        }

        return false;
    }
}
