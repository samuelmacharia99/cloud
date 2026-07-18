<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'reference_id',
        'reference_type',
        'status',
        'performed_by',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ResellerWallet::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function scopeDeposits(Builder $query): Builder
    {
        return $query->where('type', 'deposit');
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->whereIn('type', ['domain_debit', 'subscription_debit']);
    }

    public function isDebit(): bool
    {
        return in_array($this->type, ['domain_debit', 'subscription_debit'], true);
    }

    public function scopeRefunds(Builder $query): Builder
    {
        return $query->where('type', 'refund');
    }

    public function scopeAdjustments(Builder $query): Builder
    {
        return $query->where('type', 'adjustment');
    }
}
