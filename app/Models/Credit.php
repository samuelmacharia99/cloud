<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Credit extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'source',
        'payment_id',
        'invoice_id',
        'notes',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user who owns this credit
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the payment that created this credit (if from overpayment)
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the invoice this credit is tied to
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get all invoices this credit has been applied to
     */
    public function appliedToInvoices(): BelongsToMany
    {
        return $this->belongsToMany(
            Invoice::class,
            'credit_applications',
            'credit_id',
            'invoice_id'
        )->withPivot('amount_applied')
        ->withTimestamps();
    }

    /**
     * Get available balance for this credit
     */
    public function getAvailableBalance(): float
    {
        if ($this->status === 'applied' || $this->status === 'refunded') {
            return 0;
        }

        $totalApplied = $this->appliedToInvoices()
            ->sum('credit_applications.amount_applied') ?? 0;

        return $this->amount - $totalApplied;
    }

    /**
     * Check if credit is still active
     */
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at < now()) {
            return false;
        }

        return $this->getAvailableBalance() > 0;
    }

    /**
     * Apply credit to invoice
     */
    public function applyToInvoice(Invoice $invoice, float $amount): bool
    {
        if ($amount > $this->getAvailableBalance()) {
            return false;
        }

        // Create credit application record
        \DB::table('credit_applications')->insert([
            'credit_id' => $this->id,
            'invoice_id' => $invoice->id,
            'amount_applied' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update credit status if fully applied
        $available = $this->getAvailableBalance();
        if ($available <= 0) {
            $this->update(['status' => 'applied']);
        }

        return true;
    }

    /**
     * Remove credit application
     */
    public function removeFromInvoice(Invoice $invoice): bool
    {
        $deleted = $this->appliedToInvoices()
            ->wherePivot('invoice_id', $invoice->id)
            ->detach();

        if ($deleted && $this->status === 'applied') {
            $this->update(['status' => 'active']);
        }

        return true;
    }

    /**
     * Scope: Get active credits only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
    }

    /**
     * Scope: Get available credits (with balance)
     */
    public function scopeAvailable($query)
    {
        return $query->active()
            ->where('status', 'active');
    }

    /**
     * Scope: Filter by source
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope: For a specific user
     */
    public function scopeForUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('user_id', $userId);
    }
}
