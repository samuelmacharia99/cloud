<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Services\Billing\InvoiceCurrencyService;
use App\Services\ResellerPackageSubscriptionService;
use App\Support\CurrencyFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'invoice_number',
        'status',
        'due_date',
        'paid_date',
        'subtotal',
        'tax',
        'total',
        'currency',
        'exchange_rate',
        'subtotal_base_kes',
        'tax_base_kes',
        'total_base_kes',
        'wallet_amount_applied',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'subtotal_base_kes' => 'decimal:2',
        'tax_base_kes' => 'decimal:2',
        'total_base_kes' => 'decimal:2',
        'wallet_amount_applied' => 'decimal:2',
        'status' => InvoiceStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            app(InvoiceCurrencyService::class)->applySnapshot($invoice);
        });

        static::updated(function (self $invoice) {
            if ($invoice->wasChanged('status') && $invoice->isPaid()) {
                app(ResellerPackageSubscriptionService::class)
                    ->activateFromPaidInvoice($invoice);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->hasOne(Order::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return array<int, string>
     */
    public static function itemDisplayRelations(): array
    {
        return [
            'items.product',
            'items.domain',
            'items.service.product',
            'items.service.containerDeployment.domains',
        ];
    }

    public function loadItemsForDisplay(): self
    {
        $this->load(self::itemDisplayRelations());

        return $this;
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function credits()
    {
        return $this->belongsToMany(
            Credit::class,
            'credit_applications',
            'invoice_id',
            'credit_id'
        )->withPivot('amount_applied')
            ->withTimestamps();
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['unpaid', 'overdue']) && $this->due_date?->isPast();
    }

    public function getAmountPaid(): float
    {
        $currencyService = app(InvoiceCurrencyService::class);
        $paid = 0.0;

        $this->payments()
            ->where('status', 'completed')
            ->get()
            ->each(function (Payment $payment) use ($currencyService, &$paid) {
                $paid += $currencyService->paymentAmountInInvoiceCurrency(
                    $this,
                    (float) $payment->amount,
                    $payment->currency ?? config('currency.base', 'KES')
                );
            });

        return round($paid, 2);
    }

    public function isKesLedger(): bool
    {
        return app(InvoiceCurrencyService::class)->isKesLedgerInvoice($this);
    }

    public function getAmountRemaining(): float
    {
        $walletApplied = (float) ($this->wallet_amount_applied ?? 0);

        return max(0, round(
            (float) $this->total - $walletApplied - $this->getAmountPaid() - $this->getAppliedCredits(),
            2
        ));
    }

    /**
     * Get total credits applied to this invoice
     */
    public function getAppliedCredits(): float
    {
        $kesApplied = (float) ($this->credits()->sum('amount_applied') ?? 0);

        if ($kesApplied <= 0 || $this->displayCurrency() === config('currency.base', 'KES')) {
            return $kesApplied;
        }

        return round($kesApplied * (float) $this->exchange_rate, 2);
    }

    public function displayCurrency(): string
    {
        return $this->currency ?? config('currency.base', 'KES');
    }

    public function formatMoney(float $amount): string
    {
        return CurrencyFormatter::format($amount, $this->displayCurrency());
    }

    /**
     * Check if invoice is fully paid (including credits)
     */
    public function isFullyPaid(): bool
    {
        return $this->getAmountRemaining() <= 0;
    }

    public function amountDue(): float
    {
        return max(0, round((float) $this->total - (float) ($this->wallet_amount_applied ?? 0), 2));
    }

    public function scopeResellerSubscription($query)
    {
        return $query->where('type', 'reseller_subscription');
    }
}
