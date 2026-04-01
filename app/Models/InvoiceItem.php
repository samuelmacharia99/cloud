<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'service_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

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
}
