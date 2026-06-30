<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'invoice_id',
        'order_number',
        'status',
        'payment_status',
        'subtotal',
        'tax',
        'total',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function domainRenewalOrder(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DomainRenewalOrder::class, 'admin_order_id');
    }

    public function isDomainRenewalFulfillment(): bool
    {
        return $this->relationLoaded('domainRenewalOrder')
            ? $this->domainRenewalOrder !== null
            : $this->domainRenewalOrder()->exists();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function canAdminDelete(): bool
    {
        return $this->isPending() && $this->payment_status === 'unpaid';
    }
}
