<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'invoice_id', 'amount', 'currency',
        'payment_method', 'transaction_reference', 'status', 'paid_at', 'notes',
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
}
