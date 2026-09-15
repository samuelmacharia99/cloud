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
        'max_services',
        'disk_pool_gb',
        'disk_overage_rate',
        'cpu_pool_cores',
        'memory_pool_mb',
        'bandwidth_pool_gb',
        'cpu_overage_rate',
        'memory_overage_rate',
        'bandwidth_overage_rate',
        'backups_included',
        'max_users',
        'price',
        'active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'storage_space' => 'integer',
        'max_services' => 'integer',
        'disk_pool_gb' => 'integer',
        'disk_overage_rate' => 'decimal:4',
        'cpu_pool_cores' => 'decimal:2',
        'memory_pool_mb' => 'integer',
        'bandwidth_pool_gb' => 'integer',
        'cpu_overage_rate' => 'decimal:4',
        'memory_overage_rate' => 'decimal:4',
        'bandwidth_overage_rate' => 'decimal:4',
        'backups_included' => 'boolean',
        'max_users' => 'integer',
    ];

    /**
     * Packages are sold by resources; a customer or service cap is optional.
     * Zero (or nothing) means unlimited.
     */
    public function hasUserCap(): bool
    {
        return (int) ($this->attributes['max_users'] ?? 0) > 0;
    }

    /**
     * An explicit 0 is unlimited. Null is a package from before the column
     * existed, which still means the old storage_space slot count.
     */
    public function hasServiceCap(): bool
    {
        return $this->max_services > 0;
    }

    public function userCapLabel(): string
    {
        return $this->hasUserCap() ? number_format((int) $this->attributes['max_users']) : 'Unlimited';
    }

    public function serviceCapLabel(): string
    {
        return $this->hasServiceCap() ? number_format($this->max_services) : 'Unlimited';
    }

    public function getMaxUsersAttribute($value): int
    {
        return (int) ($value ?? 0);
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2).' KES';
    }

    public function getStorageFormattedAttribute(): string
    {
        return number_format($this->disk_pool_gb ?: $this->storage_space).' GB';
    }

    /**
     * Maximum concurrent active hosting services.
     */
    public function getMaxServicesAttribute(): int
    {
        if (array_key_exists('max_services', $this->attributes) && $this->attributes['max_services'] !== null) {
            return (int) $this->attributes['max_services'];
        }

        return (int) ($this->attributes['storage_space'] ?? 0);
    }

    public function getDiskPoolGbAttribute(): int
    {
        $pool = $this->attributes['disk_pool_gb'] ?? null;

        if ($pool !== null && (int) $pool > 0) {
            return (int) $pool;
        }

        return (int) $this->storage_space;
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
