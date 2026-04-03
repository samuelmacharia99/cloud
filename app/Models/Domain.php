<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'extension',
        'registrar',
        'status',
        'registered_at',
        'expires_at',
        'auto_renew',
        'nameserver_1',
        'nameserver_2',
        'notes',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function domainExtension()
    {
        return $this->belongsTo(DomainExtension::class, 'extension', 'extension');
    }

    public function dnsZones()
    {
        return $this->hasMany(DnsZone::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function daysUntilExpiry(): int
    {
        return now()->diffInDays($this->expires_at);
    }
}
