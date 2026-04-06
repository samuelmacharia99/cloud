<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerDomain extends Model
{
    protected $fillable = [
        'container_deployment_id',
        'domain',
        'status',
        'ssl_enabled',
        'ssl_certificate_path',
        'ssl_key_path',
        'nginx_config_path',
        'verified_at',
        'error_message',
    ];

    protected $casts = [
        'ssl_enabled' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the container deployment this domain belongs to
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ContainerDeployment::class, 'container_deployment_id');
    }

    /**
     * Check if domain is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if domain has SSL enabled
     */
    public function hasSsl(): bool
    {
        return $this->ssl_enabled && $this->isActive();
    }

    /**
     * Get status color for UI display
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'bg-blue-100 text-blue-800',
            'active' => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
            'removing' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}
