<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerDeployment extends Model
{
    protected $fillable = [
        'service_id',
        'node_id',
        'container_name',
        'status',
        'docker_compose_content',
        'assigned_port',
        'internal_ip',
        'domain',
        'env_values',
        'last_status_check_at',
        'last_status_check_output',
        'deployed_at',
        'terminated_at',
    ];

    protected $casts = [
        'env_values' => 'array',
        'assigned_port' => 'integer',
        'deployed_at' => 'datetime',
        'terminated_at' => 'datetime',
        'last_status_check_at' => 'datetime',
    ];

    // Relationships
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    // Status Helpers
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isStopped(): bool
    {
        return $this->status === 'stopped';
    }

    public function isDeploying(): bool
    {
        return $this->status === 'deploying';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    // Access URL Helper
    public function getAccessUrl(): ?string
    {
        if ($this->domain) {
            return "https://{$this->domain}";
        }

        if ($this->node && $this->assigned_port) {
            return "http://{$this->node->ip_address}:{$this->assigned_port}";
        }

        return null;
    }

    // Uptime helper
    public function getUptimeSeconds(): ?int
    {
        if (! $this->deployed_at) {
            return null;
        }

        return now()->diffInSeconds($this->deployed_at);
    }
}
