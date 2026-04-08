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
        'type',
        'registrar',
        'status',
        'transfer_status',
        'registered_at',
        'expires_at',
        'auto_renew',
        'nameserver_1',
        'nameserver_2',
        'epp_code',
        'old_registrar',
        'old_registrar_url',
        'transfer_initiated_at',
        'transfer_completed_at',
        'transfer_notes',
        'transfer_authorization_code',
        'notes',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'transfer_initiated_at' => 'datetime',
        'transfer_completed_at' => 'datetime',
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

    /**
     * Check if domain is a transfer
     */
    public function isTransfer(): bool
    {
        return $this->type === 'transfer';
    }

    /**
     * Check if domain is registered
     */
    public function isRegistration(): bool
    {
        return $this->type === 'registration';
    }

    /**
     * Check if transfer is pending
     */
    public function isTransferPending(): bool
    {
        return $this->transfer_status === 'pending';
    }

    /**
     * Check if transfer is in progress
     */
    public function isTransferInProgress(): bool
    {
        return $this->transfer_status === 'in_progress';
    }

    /**
     * Check if transfer is completed
     */
    public function isTransferCompleted(): bool
    {
        return $this->transfer_status === 'completed';
    }

    /**
     * Check if transfer failed
     */
    public function isTransferFailed(): bool
    {
        return $this->transfer_status === 'failed';
    }

    /**
     * Get transfer status color for UI
     */
    public function getTransferStatusColor(): string
    {
        return match ($this->transfer_status) {
            'pending' => 'amber',
            'initiated' => 'blue',
            'in_progress' => 'blue',
            'completed' => 'emerald',
            'failed' => 'red',
            'cancelled' => 'slate',
            default => 'slate',
        };
    }

    /**
     * Get transfer status label
     */
    public function getTransferStatusLabel(): string
    {
        return match ($this->transfer_status) {
            'pending' => 'Awaiting EPP Code',
            'initiated' => 'Transfer Initiated',
            'in_progress' => 'Transfer In Progress',
            'completed' => 'Transfer Completed',
            'failed' => 'Transfer Failed',
            'cancelled' => 'Transfer Cancelled',
            default => 'Unknown',
        };
    }

    /**
     * Scope: Get pending transfers
     */
    public function scopePendingTransfers($query)
    {
        return $query->where('type', 'transfer')
            ->where('transfer_status', 'pending');
    }

    /**
     * Scope: Get transfers in progress
     */
    public function scopeInProgressTransfers($query)
    {
        return $query->where('type', 'transfer')
            ->where('transfer_status', 'in_progress');
    }

    /**
     * Scope: Get completed transfers
     */
    public function scopeCompletedTransfers($query)
    {
        return $query->where('type', 'transfer')
            ->where('transfer_status', 'completed');
    }
}
