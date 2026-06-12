<?php

namespace App\Models;

use App\Services\Billing\InvoiceCurrencyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'service_id',
        'product_id',
        'product_type',
        'domain_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'custom_options',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'custom_options' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            app(InvoiceCurrencyService::class)->convertItemToInvoiceCurrency($item);
        });
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
