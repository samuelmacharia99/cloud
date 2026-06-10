<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerDomainOrder extends Model
{
    protected $fillable = [
        'reseller_id',
        'customer_id',
        'domain_id',
        'wallet_transaction_id',
        'admin_order_id',
        'admin_invoice_id',
        'customer_invoice_id',
        'domain_name',
        'extension',
        'years',
        'wholesale_amount',
        'retail_amount',
        'status',
        'push_mode',
        'queued_at',
        'pushed_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'failure_reason',
        'retry_count',
        'expires_at',
    ];

    protected $casts = [
        'wholesale_amount' => 'decimal:2',
        'retail_amount' => 'decimal:2',
        'queued_at' => 'datetime',
        'pushed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function adminOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'admin_order_id');
    }

    public function adminInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'admin_invoice_id');
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'customer_invoice_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || (
            $this->status === 'queued' && $this->expires_at?->isPast()
        );
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < 3;
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['queued', 'failed', 'expired'], true);
    }

    public function canDelete(): bool
    {
        return in_array($this->status, ['cancelled', 'failed', 'expired'], true);
    }

    public function canAdminPush(): bool
    {
        return $this->status === 'queued';
    }

    public function hasPaidWholesaleInvoice(): bool
    {
        return $this->paidWholesaleInvoice() !== null;
    }

    public function paidWholesaleInvoice(): ?Invoice
    {
        return Invoice::query()
            ->where('user_id', $this->reseller_id)
            ->whereHas('items', function ($query) {
                $query->where('product_type', 'Domain')
                    ->where('custom_options->domain_order_id', $this->id);
            })
            ->orderByDesc('id')
            ->get()
            ->first(fn (Invoice $invoice) => $invoice->isPaid());
    }

    public function canAdminComplete(): bool
    {
        return $this->status === 'pushed'
            || ($this->status === 'queued' && $this->hasPaidWholesaleInvoice());
    }

    public function canAdminDelete(): bool
    {
        return in_array($this->status, ['queued', 'cancelled', 'failed', 'expired'], true);
    }

    public function isSelfOrder(): bool
    {
        return (int) $this->customer_id === (int) $this->reseller_id;
    }

    public function customerLabel(): string
    {
        return $this->isSelfOrder()
            ? 'Reseller (self)'
            : ($this->customer?->name ?? '—');
    }

    public function fullDomainName(): string
    {
        $extension = (string) $this->extension;

        if ($extension !== '' && ! str_starts_with($extension, '.')) {
            $extension = '.'.$extension;
        }

        return $this->domain_name.$extension;
    }

    /**
     * Domain registrations placed by customers managed by this reseller (not the reseller's own orders).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForManagedCustomers(Builder $query, User $reseller): Builder
    {
        return $query
            ->where('reseller_id', $reseller->id)
            ->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('reseller_id', $reseller->id));
    }
}
