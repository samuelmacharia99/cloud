<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerFileAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'user_id',
        'deployment_id',
        'action',
        'path',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
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

    public function scopeForService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }
}
