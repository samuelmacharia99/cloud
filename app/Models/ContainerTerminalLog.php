<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerTerminalLog extends Model
{
    protected $fillable = [
        'session_id', 'user_id', 'service_id', 'command', 'sanitized_command',
        'output', 'exit_code', 'execution_ms', 'cwd', 'ip_address',
        'is_blocked', 'block_reason',
    ];

    protected $casts = [
        'exit_code' => 'integer',
        'execution_ms' => 'integer',
        'is_blocked' => 'boolean',
    ];

    public $timestamps = false;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    public function session(): BelongsTo
    {
        return $this->belongsTo(ContainerTerminalSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
