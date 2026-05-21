<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerTerminalSession extends Model
{
    protected $fillable = [
        'token', 'service_id', 'user_id', 'deployment_id', 'container_name',
        'cwd', 'command_history', 'status', 'ip_address', 'user_agent',
        'command_count', 'last_activity_at', 'expires_at', 'hard_expires_at',
    ];

    protected $casts = [
        'command_history' => 'array',
        'command_count' => 'integer',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'hard_expires_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ContainerDeployment::class, 'deployment_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ContainerTerminalLog::class, 'session_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function isExpired(): bool
    {
        if ($this->status !== 'active') {
            return true;
        }

        if ($this->hard_expires_at && now()->gt($this->hard_expires_at)) {
            return true;
        }

        if ($this->expires_at && now()->gt($this->expires_at)) {
            return true;
        }

        return false;
    }

    public function extendExpiry(): void
    {
        $this->update([
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function addToHistory(string $command): void
    {
        $history = $this->command_history ?? [];

        $history[] = $command;

        // Keep only last 50 commands
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        $this->update(['command_history' => $history]);
    }

    public function close(): void
    {
        $this->update([
            'status' => 'closed',
            'expires_at' => now(),
        ]);
    }
}
