<?php

namespace App\Models;

use App\Services\ResellerProvisionProductResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ResellerProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'product_id',
        'container_template_id',
        'database_template_id',
        'name',
        'slug',
        'description',
        'type',
        'direct_admin_package_name',
        'resource_limits',
        'features',
        'monthly_price',
        'yearly_price',
        'setup_fee',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'resource_limits' => 'array',
            'features' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ResellerProduct $product): void {
            if (filled($product->slug)) {
                $product->slug = static::normalizeSlug((string) $product->slug) ?: 'product';
            } elseif (filled($product->name)) {
                $product->slug = static::normalizeSlug((string) $product->name) ?: 'product';
            }

            if (! filled($product->slug) || ! $product->reseller_id) {
                return;
            }

            $product->slug = static::uniqueSlugForReseller(
                (int) $product->reseller_id,
                (string) $product->slug,
                $product->exists ? (int) $product->id : null,
            );
        });
    }

    public static function normalizeSlug(string $value): string
    {
        return Str::slug(trim($value));
    }

    public static function uniqueSlugForReseller(int $resellerId, string $nameOrSlug, ?int $ignoreId = null): string
    {
        $base = static::normalizeSlug($nameOrSlug) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('reseller_id', $resellerId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Relative WordPress-friendly order link: /{slug}/cart
     */
    public function orderCartPath(?string $billingCycle = null): ?string
    {
        if (! filled($this->slug)) {
            return null;
        }

        $path = '/'.$this->slug.'/cart';
        if ($billingCycle && $billingCycle !== 'monthly') {
            $path .= '?billing_cycle='.urlencode($billingCycle);
        }

        return $path;
    }

    /**
     * Absolute order URL on the reseller branding domain (when configured).
     */
    public function orderCartUrl(?string $billingCycle = null): ?string
    {
        $path = $this->orderCartPath($billingCycle);
        if ($path === null) {
            return null;
        }

        $this->loadMissing('reseller');
        $host = data_get($this->reseller?->settings, 'branding.custom_domain');
        $host = is_string($host) ? strtolower(trim($host)) : '';
        $host = preg_replace('#^https?://#', '', $host) ?? '';
        $host = rtrim($host, '/');

        if ($host === '') {
            return null;
        }

        return 'https://'.$host.$path;
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function adminProduct()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function containerTemplate()
    {
        return $this->belongsTo(ContainerTemplate::class);
    }

    public function databaseTemplate()
    {
        return $this->belongsTo(DatabaseTemplate::class);
    }

    /**
     * @return array{cpu: float|null, memory_mb: int|null, disk_gb: float|null}
     */
    public function containerResourceLimits(): array
    {
        $limits = $this->resource_limits ?? [];

        return [
            'cpu' => isset($limits['cpu']) ? (float) $limits['cpu'] : null,
            'memory_mb' => isset($limits['memory_mb']) ? (int) $limits['memory_mb'] : null,
            'disk_gb' => isset($limits['disk_gb']) ? (float) $limits['disk_gb'] : null,
        ];
    }

    public function hasContainerResourceLimits(): bool
    {
        if ($this->type !== 'container_hosting') {
            return false;
        }

        $limits = $this->containerResourceLimits();

        return $limits['cpu'] !== null || $limits['memory_mb'] !== null || $limits['disk_gb'] !== null;
    }

    public function isCustom(): bool
    {
        return is_null($this->product_id);
    }

    public function usesDirectAdminPackage(): bool
    {
        return $this->type === 'shared_hosting' && filled($this->direct_admin_package_name);
    }

    public function isOrderable(): bool
    {
        return app(ResellerProvisionProductResolver::class)->isOrderable($this);
    }

    public function provisionProduct(): ?Product
    {
        return app(ResellerProvisionProductResolver::class)->resolve($this);
    }

    /**
     * @return array<string, string>
     */
    public function directAdminPackageMeta(): array
    {
        $name = trim((string) $this->direct_admin_package_name);

        // DirectAdmin MODIFY_USER / ACCOUNT_USER expect the exact package name from
        // CMD_API_PACKAGES_USER — slugging breaks names like "Gold Package".
        return [
            'package_name' => $name,
            'package' => $name,
        ];
    }

    public function getWholesaleMonthlyCost(): ?float
    {
        if ($this->isCustom() || $this->type === 'container_hosting') {
            return null;
        }

        return (float) $this->adminProduct?->wholesale_monthly_price;
    }

    public function getWholesaleYearlyCost(): ?float
    {
        if ($this->isCustom() || $this->type === 'container_hosting') {
            return null;
        }

        return (float) $this->adminProduct?->wholesale_yearly_price;
    }

    public function getMonthlyMargin(): ?float
    {
        $wholesale = $this->getWholesaleMonthlyCost();
        if (is_null($wholesale) || is_null($this->monthly_price)) {
            return null;
        }

        return (float) $this->monthly_price - $wholesale;
    }

    public function getMonthlyMarginPercent(): ?float
    {
        $wholesale = $this->getWholesaleMonthlyCost();
        if (is_null($wholesale) || is_null($this->monthly_price) || $wholesale == 0) {
            return null;
        }

        return ($this->getMonthlyMargin() / $wholesale) * 100;
    }

    public function getYearlyMargin(): ?float
    {
        $wholesale = $this->getWholesaleYearlyCost();
        if (is_null($wholesale) || is_null($this->yearly_price)) {
            return null;
        }

        return (float) $this->yearly_price - $wholesale;
    }

    public function getYearlyMarginPercent(): ?float
    {
        $wholesale = $this->getWholesaleYearlyCost();
        if (is_null($wholesale) || is_null($this->yearly_price) || $wholesale == 0) {
            return null;
        }

        return ($this->getYearlyMargin() / $wholesale) * 100;
    }

    public function priceForBillingCycle(string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) ($this->monthly_price ?? 0),
            'quarterly' => (float) (($this->monthly_price ?? 0) * 3),
            'semi-annual' => (float) (($this->monthly_price ?? 0) * 6),
            'annual' => (float) ($this->yearly_price ?? (($this->monthly_price ?? 0) * 12)),
            default => 0,
        };
    }
}
