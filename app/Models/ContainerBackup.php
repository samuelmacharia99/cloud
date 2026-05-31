<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'container_deployment_id',
        'service_id',
        'node_id',
        'backup_name',
        'backup_path',
        'size_bytes',
        'status',
        'type',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ContainerDeployment::class, 'container_deployment_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
