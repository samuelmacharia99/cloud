<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerWallet extends Model
{
    protected $fillable = [
        'reseller_id',
        'balance',
        'currency',
        'status',
        'low_balance_threshold',
        'last_low_balance_alert_at',
        'auto_push_enabled',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'low_balance_threshold' => 'decimal:2',
        'auto_push_enabled' => 'boolean',
        'last_low_balance_alert_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    public function domainOrders(): HasMany
    {
        return $this->hasMany(ResellerDomainOrder::class, 'reseller_id', 'reseller_id');
    }

    public function hasSufficientFunds(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function isLowBalance(): bool
    {
        return $this->balance < $this->low_balance_threshold;
    }

    public function needsLowBalanceAlert(): bool
    {
        if (! $this->isLowBalance()) {
            return false;
        }

        if ($this->last_low_balance_alert_at === null) {
            return true;
        }

        return $this->last_low_balance_alert_at->diffInHours(now()) >= 24;
    }

    public function getFormattedBalance(): string
    {
        $label = $this->currency === 'KES' ? 'KSH' : $this->currency;

        return "{$label} ".number_format($this->balance, 2);
    }
}
