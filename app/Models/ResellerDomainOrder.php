<?php

namespace App\Models;

use App\Enums\ResellerDomainOrderType;
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
        'order_type',
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
        'order_type' => ResellerDomainOrderType::class,
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

    public function isPlatformOrder(): bool
    {
        return $this->reseller_id === null;
    }

    public function isTransfer(): bool
    {
        return ($this->order_type ?? ResellerDomainOrderType::Registration) === ResellerDomainOrderType::Transfer;
    }

    public function isRegistration(): bool
    {
        return ! $this->isTransfer();
    }

    public function requiresCustomerPaymentBeforePush(): bool
    {
        return $this->customer_invoice_id !== null;
    }

    public function canAdminPush(): bool
    {
        if ($this->status !== 'queued') {
            return false;
        }

        if ($this->isPlatformOrder()) {
            return $this->hasPaidCustomerInvoice();
        }

        if ($this->requiresCustomerPaymentBeforePush()) {
            return $this->hasPaidCustomerInvoice();
        }

        return true;
    }

    public function canResellerPush(): bool
    {
        return $this->canAdminPush();
    }

    public function hasPaidCustomerInvoice(): bool
    {
        return $this->paidCustomerInvoice() !== null;
    }

    public function paidCustomerInvoice(): ?Invoice
    {
        if ($this->customer_invoice_id) {
            $invoice = Invoice::find($this->customer_invoice_id);

            return $invoice?->isPaid() ? $invoice : null;
        }

        return Invoice::query()
            ->where('user_id', $this->customer_id)
            ->whereHas('items', function ($query) {
                $query->where('product_type', 'Domain')
                    ->where('custom_options->domain_order_id', $this->id);
            })
            ->orderByDesc('id')
            ->get()
            ->first(fn (Invoice $invoice) => $invoice->isPaid());
    }

    public function hasPaidWholesaleInvoice(): bool
    {
        if ($this->isPlatformOrder()) {
            return false;
        }

        return $this->paidWholesaleInvoice() !== null;
    }

    public function paidWholesaleInvoice(): ?Invoice
    {
        if ($this->isPlatformOrder() || $this->reseller_id === null) {
            return null;
        }

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
        return in_array($this->status, ['pushed', 'failed'], true)
            || ($this->status === 'queued' && ($this->hasPaidWholesaleInvoice() || $this->hasPaidCustomerInvoice()));
    }

    /**
     * Submit or retry registration/transfer at the linked API registrar (e.g. Openprovider).
     */
    public function canAdminPushToRegistrar(): bool
    {
        if (! in_array($this->status, ['pushed', 'failed'], true)) {
            return false;
        }

        $domain = $this->relationLoaded('domain') ? $this->domain : $this->domain()->first();

        if (! $domain) {
            return false;
        }

        if ($domain->status === 'active' && $domain->registrar_external_id) {
            return false;
        }

        return true;
    }

    public function canAdminDelete(): bool
    {
        return in_array($this->status, ['queued', 'cancelled', 'failed', 'expired'], true);
    }

    public function isSelfOrder(): bool
    {
        if ($this->isPlatformOrder()) {
            return false;
        }

        return (int) $this->customer_id === (int) $this->reseller_id;
    }

    public function resellerLabel(): string
    {
        return $this->isPlatformOrder()
            ? 'Platform (direct)'
            : ($this->reseller?->name ?? '—');
    }

    public function customerLabel(): string
    {
        if ($this->isPlatformOrder()) {
            return $this->customer?->name ?? '—';
        }

        return $this->isSelfOrder()
            ? 'Reseller (self)'
            : ($this->customer?->name ?? '—');
    }

    public function displayAmount(): float
    {
        return (float) $this->wholesale_amount + (float) $this->retail_amount;
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
