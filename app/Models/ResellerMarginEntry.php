<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerMarginEntry extends Model
{
    protected $fillable = [
        'reseller_id',
        'customer_id',
        'invoice_id',
        'payment_id',
        'entry_type',
        'description',
        'retail_amount',
        'wholesale_amount',
        'margin_amount',
    ];

    protected function casts(): array
    {
        return [
            'retail_amount' => 'decimal:2',
            'wholesale_amount' => 'decimal:2',
            'margin_amount' => 'decimal:2',
        ];
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
