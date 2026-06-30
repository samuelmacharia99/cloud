<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResellerDomainPricing extends Model
{
    use HasFactory;

    protected $table = 'reseller_domain_pricing';

    protected $fillable = [
        'reseller_id',
        'domain_extension_id',
        'period_years',
        'retail_price',
        'renewal_retail_price',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'retail_price' => 'decimal:2',
            'renewal_retail_price' => 'decimal:2',
        ];
    }

    public function effectiveRenewalRetailPrice(): float
    {
        if ($this->renewal_retail_price !== null) {
            return (float) $this->renewal_retail_price;
        }

        return (float) $this->retail_price;
    }

    public function getWholesaleRenewalPrice(): ?float
    {
        $wholesale = $this->extension?->getWholesalePricing($this->period_years);

        if (! $wholesale) {
            return null;
        }

        return (float) ($wholesale->renewal_price ?? $wholesale->price);
    }

    public function getRenewalMargin(): ?float
    {
        $wholesale = $this->getWholesaleRenewalPrice();
        if ($wholesale === null) {
            return null;
        }

        return $this->effectiveRenewalRetailPrice() - $wholesale;
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function extension()
    {
        return $this->belongsTo(DomainExtension::class, 'domain_extension_id');
    }

    public function getWholesalePrice(): ?float
    {
        $wholesale = $this->extension?->getWholesalePricing($this->period_years);

        return $wholesale ? (float) $wholesale->price : null;
    }

    public function getMargin(): ?float
    {
        $wholesale = $this->getWholesalePrice();
        if (is_null($wholesale)) {
            return null;
        }

        return (float) $this->retail_price - $wholesale;
    }

    public function getMarginPercent(): ?float
    {
        $wholesale = $this->getWholesalePrice();
        if (is_null($wholesale) || $wholesale == 0) {
            return null;
        }
        $margin = $this->getMargin();

        return $margin ? (($margin / $wholesale) * 100) : 0;
    }
}
