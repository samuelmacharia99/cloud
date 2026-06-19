<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\Billing\InvoiceCurrencyService;
use App\Services\CreditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'invoice_id', 'amount', 'currency',
        'payment_method', 'payment_purpose', 'transaction_reference', 'status', 'paid_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'currency' => 'string',
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function credit()
    {
        return $this->hasOne(Credit::class);
    }

    // Status Checks
    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::Reversed;
    }

    /**
     * Check if this payment is an overpayment
     */
    public function isOverpayment(): bool
    {
        if (! $this->invoice) {
            return false;
        }

        $currencyService = app(InvoiceCurrencyService::class);
        $paymentInInvoice = $currencyService->paymentAmountInInvoiceCurrency(
            $this->invoice,
            (float) $this->amount,
            $this->currency ?? config('currency.base', 'KES')
        );

        return $paymentInInvoice > $this->invoice->getAmountRemaining() + 0.01;
    }

    /**
     * Get overpayment amount in KES (account credit ledger).
     */
    public function getOverpaymentAmount(): float
    {
        if (! $this->invoice || ! $this->isOverpayment()) {
            return 0;
        }

        return app(InvoiceCurrencyService::class)->paymentOverpaymentInKes(
            $this->invoice,
            (float) $this->amount,
            $this->currency ?? config('currency.base', 'KES')
        );
    }

    /**
     * Process overpayment as credit
     */
    public function createCreditFromOverpayment(): ?Credit
    {
        if (! $this->isOverpayment()) {
            return null;
        }

        return CreditService::createFromOverpayment($this);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', PaymentStatus::Completed->value);
    }

    public function scopePending($query)
    {
        return $query->where('status', PaymentStatus::Pending->value);
    }

    public function scopeByMethod($query, PaymentMethod|string $method)
    {
        $methodValue = $method instanceof PaymentMethod ? $method->value : $method;

        return $query->where('payment_method', $methodValue);
    }

    public function scopeByUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * Payments that count as platform (admin) revenue — excludes reseller-managed customer retail.
     */
    public function scopePlatformRevenue(Builder $query): Builder
    {
        return $query->where(function (Builder $outer) {
            $outer->where('payment_purpose', 'wallet_topup')
                ->orWhereHas('invoice', function (Builder $invoice) {
                    $invoice->where(function (Builder $inner) {
                        $inner->where('type', 'reseller_subscription')
                            ->orWhereHas('user', function (Builder $user) {
                                $user->whereNull('reseller_id')
                                    ->where('is_reseller', false)
                                    ->where('is_admin', false);
                            });
                    });
                });
        });
    }
}
