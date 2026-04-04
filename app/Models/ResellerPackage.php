<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerPackage extends Model
{
    protected $fillable = [
        'name',
        'description',
        'billing_cycle',
        'storage_space',
        'max_users',
        'price',
        'active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'storage_space' => 'integer',
        'max_users' => 'integer',
    ];

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2) . ' KES';
    }

    public function getStorageFormattedAttribute(): string
    {
        return number_format($this->storage_space) . ' GB';
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(User::class, 'reseller_package_id');
    }

    /**
     * Returns the next higher package by price in the same billing cycle.
     * Returns null if this is already the highest tier.
     */
    public function nextPackage(): ?self
    {
        return self::where('active', true)
                   ->where('billing_cycle', $this->billing_cycle)
                   ->where('price', '>', $this->price)
                   ->orderBy('price', 'asc')
                   ->first();
    }

    /**
     * Returns all packages with a higher price in the same billing cycle.
     */
    public function higherTierPackages()
    {
        return self::where('active', true)
                   ->where('billing_cycle', $this->billing_cycle)
                   ->where('price', '>', $this->price)
                   ->orderBy('price', 'asc')
                   ->get();
    }
}
