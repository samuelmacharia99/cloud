<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainExtension extends Model
{
    protected $table = 'domain_extensions';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    // Use 'extension' field for route model binding (e.g., .com, .co.ke)
    public function getRouteKeyName()
    {
        return 'extension';
    }

    protected $fillable = [
        'extension',
        'description',
        'enabled',
        'registration_period_min',
        'registration_period_max',
        'registrar',
        'registrar_id',
        'dns_management',
        'auto_renewal',
        'transfer_price',
        'registrar_register_cost_usd',
        'registrar_renew_cost_usd',
        'registrar_transfer_cost_usd',
        'registrar_register_cost_kes',
        'registrar_renew_cost_kes',
        'registrar_transfer_cost_kes',
        'registrar_cost_synced_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'dns_management' => 'boolean',
        'auto_renewal' => 'boolean',
        'transfer_price' => 'decimal:2',
        'registrar_register_cost_usd' => 'decimal:2',
        'registrar_renew_cost_usd' => 'decimal:2',
        'registrar_transfer_cost_usd' => 'decimal:2',
        'registrar_register_cost_kes' => 'decimal:2',
        'registrar_renew_cost_kes' => 'decimal:2',
        'registrar_transfer_cost_kes' => 'decimal:2',
        'registrar_cost_synced_at' => 'datetime',
    ];

    public function registrarModel()
    {
        return $this->belongsTo(Registrar::class, 'registrar_id');
    }

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

    public function getPricingForUser(User $user, int $periodYears)
    {
        if ($user->is_reseller) {
            return $this->getWholesalePricing($periodYears) ?? $this->getRetailPricing($periodYears);
        }

        if ($user->reseller_id) {
            $resellerPricing = ResellerDomainPricing::query()
                ->where('reseller_id', $user->reseller_id)
                ->where('domain_extension_id', $this->id)
                ->where('period_years', $periodYears)
                ->where('enabled', true)
                ->first();

            if (! $resellerPricing) {
                // Match registration: reseller customers only see reseller overrides.
                return null;
            }

            $retail = (float) $resellerPricing->retail_price;
            $renewal = $resellerPricing->effectiveRenewalRetailPrice();

            return (object) [
                'price' => $retail,
                'renewal_price' => $renewal,
            ];
        }

        return $this->getRetailPricing($periodYears);
    }

    public function registrarRegistrationCost(int $periodYears = 1): ?float
    {
        if ($this->registrar_register_cost_kes === null) {
            return null;
        }

        return round((float) $this->registrar_register_cost_kes * max(1, $periodYears), 2);
    }

    public function registrarRenewalCost(int $periodYears = 1): ?float
    {
        $annual = $this->registrar_renew_cost_kes ?? $this->registrar_register_cost_kes;

        if ($annual === null) {
            return null;
        }

        return round((float) $annual * max(1, $periodYears), 2);
    }

    public function hasRegistrarCosts(): bool
    {
        return $this->registrar_register_cost_kes !== null
            || $this->registrar_renew_cost_kes !== null
            || $this->registrar_transfer_cost_kes !== null;
    }

    /**
     * Hide upstream provider details from reseller-facing responses.
     *
     * @return $this
     */
    public function concealUpstreamProviderDetails(): static
    {
        return $this->makeHidden(['registrar']);
    }
}
