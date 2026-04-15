<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainExtension extends Model
{
    protected $table = 'domain_extensions';

    protected $fillable = [
        'extension',
        'description',
        'enabled',
        'registration_period_min',
        'registration_period_max',
        'registrar',
        'dns_management',
        'auto_renewal',
        'transfer_price',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'dns_management' => 'boolean',
        'auto_renewal' => 'boolean',
        'transfer_price' => 'decimal:2',
    ];

    public function pricing()
    {
        return $this->hasMany(DomainPricing::class);
    }

    public function domains()
    {
        return $this->hasMany(Domain::class, 'extension', 'extension');
    }

    public function resellerPricing()
    {
        return $this->hasMany(ResellerDomainPricing::class, 'domain_extension_id');
    }

    public function getRetailPricing($periodYears)
    {
        return $this->pricing()
            ->where('period_years', $periodYears)
            ->where('tier', 'retail')
            ->where('enabled', true)
            ->first();
    }

    public function getWholesalePricing($periodYears)
    {
        return $this->pricing()
            ->where('period_years', $periodYears)
            ->where('tier', 'wholesale')
            ->where('enabled', true)
            ->first();
    }

    public function getPricingForPeriod($periodYears)
    {
        return $this->pricing()
            ->where('period_years', $periodYears)
            ->where('enabled', true)
            ->get()
            ->keyBy('tier');
    }
}
