<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResellerProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'product_id',
        'name',
        'description',
        'type',
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
        ];
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function adminProduct()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isCustom(): bool
    {
        return is_null($this->product_id);
    }

    public function getWholesaleMonthlyCost(): ?float
    {
        if ($this->isCustom()) {
            return null;
        }
        return (float) $this->adminProduct?->wholesale_monthly_price;
    }

    public function getWholesaleYearlyCost(): ?float
    {
        if ($this->isCustom()) {
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
        return (($this->getMonthlyMargin() / $wholesale) * 100);
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
        return (($this->getYearlyMargin() / $wholesale) * 100);
    }
}
