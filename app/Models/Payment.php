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
        'user_id', 'invoice_id', 'amount', 'currency', 'amount_base_kes',
        'payment_method', 'payment_purpose', 'transaction_reference', 'status', 'paid_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_base_kes' => 'decimal:2',
        'currency' => 'string',
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            if ($payment->amount_base_kes !== null) {
                return;
            }

            $currency = strtoupper((string) ($payment->currency ?: config('currency.base', 'KES')));
            if ($currency === config('currency.base', 'KES')) {
                $payment->amount_base_kes = round((float) $payment->amount, 2);

                return;
            }

            $rate = (float) (Currency::query()->where('code', $currency)->value('exchange_rate') ?? 0);
            if ($rate > 0) {
                $payment->amount_base_kes = round((float) $payment->amount / $rate, 2);
            }
        });
    }

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
     * Check if this payment is an overpayment (relative to what the invoice still owed before this payment).
     */
    public function isOverpayment(): bool
    {
        return $this->getOverpaymentAmount() > 0.01;
    }

    /**
     * Get overpayment amount in KES (account credit ledger).
     */
    public function getOverpaymentAmount(): float
    {
        if (! $this->invoice) {
            return 0;
        }

        return app(InvoiceCurrencyService::class)->paymentOverpaymentInKes(
            $this->invoice,
            (float) $this->amount,
            $this->currency ?? config('currency.base', 'KES'),
            $this
        );
    }

    /**
     * Whether an overpayment credit has already been issued for this payment.
     */
    public function hasOverpaymentCredit(): bool
    {
        return Credit::query()
            ->where('payment_id', $this->id)
            ->where('source', 'overpayment')
            ->exists();
    }

    /**
     * Process overpayment as credit (admin-authorized only — not called automatically).
     */
    public function createCreditFromOverpayment(): ?Credit
    {
        if (! $this->isOverpayment()) {
            return null;
        }

        if ($this->hasOverpaymentCredit()) {
            return Credit::query()
                ->where('payment_id', $this->id)
                ->where('source', 'overpayment')
                ->first();
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
     * Payments that count as platform (admin) revenue.
     *
     * Includes: direct customer invoice payments, reseller payments to the platform
     * (package subscriptions, domain wholesale, etc.), and wallet top-ups.
     * Excludes: reseller-managed customer retail and PUSH-* fulfillment ledgers.
     */
    public function scopePlatformRevenue(Builder $query): Builder
    {
        return $query->where(function (Builder $outer) {
            $outer->where('payment_purpose', 'wallet_topup')
                ->orWhereHas('invoice', function (Builder $invoice) {
                    $invoice->platformBilling();
                });
        });
    }

    /**
     * Filter by effective payment datetime (paid_at, falling back to created_at).
     */
    public function scopeWhereEffectivePaidBetween(Builder $query, mixed $start, mixed $end): Builder
    {
        return $query->where(function (Builder $outer) use ($start, $end) {
            $outer->whereBetween('paid_at', [$start, $end])
                ->orWhere(function (Builder $inner) use ($start, $end) {
                    $inner->whereNull('paid_at')
                        ->whereBetween('created_at', [$start, $end]);
                });
        });
    }

    /**
     * SQL fragment that converts payment amount to base KES using the currencies table.
     */
    public static function amountKesSumSql(string $amountColumn = 'payments.amount', string $currencyColumn = 'payments.currency'): string
    {
        $base = addslashes(config('currency.base', 'KES'));

        return "COALESCE(SUM(COALESCE(payments.amount_base_kes, CASE
            WHEN {$currencyColumn} IS NULL OR {$currencyColumn} = '{$base}' THEN {$amountColumn}
            ELSE {$amountColumn} / NULLIF((
                SELECT exchange_rate FROM currencies
                WHERE currencies.code = {$currencyColumn}
                LIMIT 1
            ), 0)
        END)), 0)";
    }

    /**
     * Sum payment amounts in base KES for the current query.
     */
    public function scopeSumAmountKes(Builder $query): float
    {
        return (float) (clone $query)
            ->reorder()
            ->selectRaw(self::amountKesSumSql().' as aggregate')
            ->value('aggregate');
    }
}
