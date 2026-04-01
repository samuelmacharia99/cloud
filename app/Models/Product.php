<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'price',
        'billing_cycle',
        'features',
        'setup_fee',
        'is_active',
        'order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
    ];

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
