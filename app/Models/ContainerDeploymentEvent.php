<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerDeploymentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'container_deployment_id',
        'event',
        'payload',
        'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ContainerDeployment::class, 'container_deployment_id');
    }
}
