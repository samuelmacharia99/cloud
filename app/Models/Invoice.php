<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'invoice_number',
        'status',
        'due_date',
        'paid_date',
        'subtotal',
        'tax',
        'total',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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
        return $this->status === 'paid';
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
}
