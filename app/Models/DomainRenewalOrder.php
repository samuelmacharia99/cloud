<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainRenewalOrder extends Model
{
    protected $fillable = [
        'domain_id',
        'user_id',
        'invoice_id',
        'admin_order_id',
        'admin_invoice_id',
        'years',
        'amount',
        'status',
        'invoiced_at',
        'paid_at',
        'pushed_at',
        'completed_at',
        'failed_at',
        'failure_reason',
        'retry_count',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoiced_at' => 'datetime',
        'paid_at' => 'datetime',
        'pushed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function adminOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'admin_order_id');
    }

    public function adminInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'admin_invoice_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || (
            $this->status === 'pending' && $this->expires_at?->isPast()
        );
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < 3;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
