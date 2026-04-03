<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainPricing extends Model
{
    protected $table = 'domain_pricing';

    protected $fillable = [
        'domain_extension_id',
        'period_years',
        'tier',
        'price',
        'setup_fee',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function getPrice()
    {
        return (float) $this->attributes['price'];
    }

    public function getSetupFee()
    {
        return (float) $this->attributes['setup_fee'];
    }

    public function domainExtension()
    {
        return $this->belongsTo(DomainExtension::class);
    }

    public function getMarginAttribute()
    {
        if (!$this->domainExtension || !$this->tier === 'wholesale') {
            return null;
        }

        $retail = $this->domainExtension->getRetailPricing($this->period_years);
        if (!$retail) {
            return null;
        }

        return $retail->price - $this->price;
    }

    public function getMarginPercentAttribute()
    {
        $margin = $this->margin;
        if (!$margin) {
            return null;
        }

        return round(($margin / $this->price) * 100, 2);
    }
}
