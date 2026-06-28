<?php

namespace App\Services\Billing;

use App\Models\Product;
use App\Models\Service;
use App\Services\ServerProductConfigService;

class ServiceRenewalPricingService
{
    public function __construct(
        private ServerProductConfigService $serverProducts,
    ) {}

    public function unitPrice(Service $service): float
    {
        if ($service->custom_price !== null) {
            return (float) $service->custom_price;
        }

        $service->loadMissing(['user', 'product']);

        if ($this->isResellerOwnedInfrastructure($service)) {
            return $this->resellerServerRenewalPrice($service);
        }

        return $this->retailPriceForCycle($service);
    }

    public function isResellerOwnedInfrastructure(Service $service): bool
    {
        $service->loadMissing(['user', 'product']);

        return $service->user?->is_reseller
            && (int) $service->user_id === (int) $service->reseller_id
            && Product::isServerType((string) ($service->product->type ?? ''));
    }

    private function resellerServerRenewalPrice(Service $service): float
    {
        $product = $service->product;
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $locationKey = $meta['location_key'] ?? null;

        if ($locationKey) {
            try {
                $pricing = $this->serverProducts->resolveOrderPricing(
                    $product,
                    null,
                    (string) $locationKey,
                    max(1, (int) ($meta['ip_count'] ?? 1)),
                    $service->billing_cycle ?? 'monthly',
                    useWholesale: true,
                );

                return (float) $pricing['unit_price'];
            } catch (\Throwable) {
                report($e);
            }
        }

        return match ($service->billing_cycle) {
            'annual' => (float) ($product->wholesale_yearly_price ?? (($product->wholesale_monthly_price ?? 0) * 12)),
            'semi-annual' => (float) (($product->wholesale_monthly_price ?? 0) * 6),
            'quarterly' => (float) (($product->wholesale_monthly_price ?? 0) * 3),
            default => (float) ($product->wholesale_monthly_price ?? 0),
        };
    }

    private function retailPriceForCycle(Service $service): float
    {
        $product = $service->product;

        return match ($service->billing_cycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => (float) ($product->monthly_price * 3),
            'semi-annual' => (float) ($product->monthly_price * 6),
            'annual' => (float) $product->yearly_price ?: ($product->monthly_price * 12),
            default => (float) $product->price,
        };
    }
}
