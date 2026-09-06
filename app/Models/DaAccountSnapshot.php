<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DaAccountSnapshot extends Model
{
    protected $fillable = [
        'service_id',
        'captured_by_user_id',
        'username',
        'primary_domain',
        'site_count',
        'database_count',
        'mailbox_count',
        'ftp_count',
        'dns_record_count',
        'dns_imported',
        'status',
        'error',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'dns_imported' => 'boolean',
        'site_count' => 'integer',
        'database_count' => 'integer',
        'mailbox_count' => 'integer',
        'ftp_count' => 'integer',
        'dns_record_count' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function isCaptured(): bool
    {
        return $this->status === 'captured' && $this->dns_imported;
    }
}
