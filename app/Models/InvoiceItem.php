<?php

namespace App\Models;

use App\Services\UserCurrencyService;
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
            if (! $item->invoice_id) {
                return;
            }

            $invoice = Invoice::query()->find($item->invoice_id);

            if (! $invoice || $invoice->displayCurrency() === config('currency.base', 'KES')) {
                return;
            }

            $rate = (float) $invoice->exchange_rate;

            if ($rate <= 0 || $rate === 1.0) {
                return;
            }

            $decimals = app(UserCurrencyService::class)->decimalsFor($invoice->displayCurrency());
            $item->unit_price = round((float) $item->unit_price * $rate, $decimals);
            $item->amount = round((float) $item->amount * $rate, $decimals);
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
