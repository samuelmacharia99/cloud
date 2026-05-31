<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Services\ResellerPackageSubscriptionService;
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
        'wallet_amount_applied',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'wallet_amount_applied' => 'decimal:2',
        'status' => InvoiceStatus::class,
    ];

    protected static function booted(): void
    {
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
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    public function getAmountRemaining(): float
    {
        return max(0, $this->total - $this->getAmountPaid() - $this->getAppliedCredits());
    }

    /**
     * Get total credits applied to this invoice
     */
    public function getAppliedCredits(): float
    {
        return $this->credits()->sum('credit_applications.amount_applied') ?? 0;
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
