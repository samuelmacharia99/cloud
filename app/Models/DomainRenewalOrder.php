<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainRenewalOrder extends Model
{
    protected $fillable = [
        'domain_id',
        'user_id',
        'reseller_id',
        'customer_id',
        'invoice_id',
        'customer_invoice_id',
        'reseller_invoice_id',
        'wallet_transaction_id',
        'admin_order_id',
        'admin_invoice_id',
        'years',
        'amount',
        'wholesale_amount',
        'retail_amount',
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
        'wholesale_amount' => 'decimal:2',
        'retail_amount' => 'decimal:2',
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

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'customer_invoice_id');
    }

    public function resellerInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'reseller_invoice_id');
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

    public function isResellerManaged(): bool
    {
        return $this->reseller_id !== null && $this->customer_id !== null;
    }

    public function isSelfRenewal(): bool
    {
        return $this->reseller_id === null
            && $this->customer_id === null
            && $this->user?->is_reseller;
    }

    public function effectiveWholesaleAmount(): float
    {
        if ($this->wholesale_amount !== null) {
            return (float) $this->wholesale_amount;
        }

        return (float) $this->amount;
    }

    public function effectiveRetailAmount(): float
    {
        if ($this->retail_amount !== null) {
            return (float) $this->retail_amount;
        }

        return (float) $this->amount;
    }

    public function paidCustomerInvoice(): ?Invoice
    {
        $invoiceId = $this->customer_invoice_id ?? ($this->isResellerManaged() ? null : $this->invoice_id);

        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::find($invoiceId);

        return $invoice?->isPaid() ? $invoice : null;
    }

    public function hasPaidCustomerInvoice(): bool
    {
        return $this->paidCustomerInvoice() !== null;
    }

    public function paidWholesaleInvoice(): ?Invoice
    {
        if ($this->wallet_transaction_id) {
            return null;
        }

        $invoiceId = $this->reseller_invoice_id ?? ($this->isSelfRenewal() ? $this->invoice_id : null);

        if (! $invoiceId) {
            return null;
        }

        $invoice = Invoice::find($invoiceId);

        return $invoice?->isPaid() ? $invoice : null;
    }

    public function hasPaidWholesaleInvoice(): bool
    {
        return $this->paidWholesaleInvoice() !== null;
    }

    public function wholesaleAlreadySettled(): bool
    {
        return $this->wallet_transaction_id !== null || $this->hasPaidWholesaleInvoice();
    }

    public function canPushToAdmin(): bool
    {
        if (in_array($this->status, ['pushed', 'completed', 'expired', 'failed'], true)) {
            return false;
        }

        if ($this->isResellerManaged()) {
            return $this->hasPaidCustomerInvoice() && $this->wholesaleAlreadySettled();
        }

        if ($this->isSelfRenewal()) {
            return $this->hasPaidWholesaleInvoice() || ($this->invoice?->isPaid() ?? false);
        }

        return $this->hasPaidCustomerInvoice() || ($this->invoice?->isPaid() ?? false);
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || (
            in_array($this->status, ['pending', 'invoiced', 'queued'], true)
            && $this->expires_at?->isPast()
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
