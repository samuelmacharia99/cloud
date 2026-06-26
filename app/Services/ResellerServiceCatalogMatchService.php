<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

class ResellerServiceCatalogMatchService
{
    public function __construct(
        private ResellerProvisionProductResolver $productResolver,
    ) {}

    /**
     * @return array{
     *     listing: ResellerProduct,
     *     product: Product,
     *     match_type: 'exact_product'|'closest_specs'|'same_type_fallback'
     * }|null
     */
    public function closestMatch(User $reseller, Service $service): ?array
    {
        $service->loadMissing('product.directAdminPackage', 'product.containerTemplate', 'containerDeployment');

        $product = $service->product;
        if (! $product) {
            return null;
        }

        $candidates = $this->activeListingsForType($reseller, (string) $product->type);
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($product->id) {
            $exact = $candidates->first(fn (ResellerProduct $listing) => (int) $listing->product_id === (int) $product->id);
            if ($exact) {
                $resolved = $this->productResolver->resolve($exact);
                if ($resolved) {
                    return [
                        'listing' => $exact,
                        'product' => $resolved,
                        'match_type' => 'exact_product',
                    ];
                }
            }
        }

        $match = match ($product->type) {
            'shared_hosting' => $this->closestSharedHostingMatch($service, $candidates),
            'container_hosting' => $this->closestContainerMatch($service, $candidates),
            default => $this->closestGenericMatch($service, $candidates),
        };

        return $match;
    }

    /**
     * @return array{
     *     listing: ResellerProduct,
     *     product: Product,
     *     match_type: 'exact_product'|'closest_specs'|'same_type_fallback'
     * }|null
     */
    public function applyMatch(User $reseller, Service $service): ?array
    {
        $match = $this->closestMatch($reseller, $service);
        if (! $match) {
            return null;
        }

        /** @var ResellerProduct $listing */
        $listing = $match['listing'];
        /** @var Product $targetProduct */
        $targetProduct = $match['product'];

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['reseller_product_id'] = $listing->id;

        if ($listing->usesDirectAdminPackage()) {
            $meta = array_merge($meta, $listing->directAdminPackageMeta());
        }

        $updates = [
            'product_id' => $targetProduct->id,
            'service_meta' => $meta,
        ];

        if ($targetProduct->provisioning_driver_key) {
            $updates['provisioning_driver_key'] = $targetProduct->provisioning_driver_key;
        }

        $service->update($updates);

        return $match;
    }

    public function clearResellerCatalogAssignment(Service $service): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        unset($meta['reseller_product_id']);

        $service->update(['service_meta' => $meta]);
    }

    /**
     * @return Collection<int, ResellerProduct>
     */
    private function activeListingsForType(User $reseller, string $type): Collection
    {
        return ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', $type)
            ->with(['adminProduct.directAdminPackage', 'adminProduct.containerTemplate'])
            ->get()
            ->filter(fn (ResellerProduct $listing) => $listing->isOrderable())
            ->values();
    }

    /**
     * @param  Collection<int, ResellerProduct>  $candidates
     * @return array{listing: ResellerProduct, product: Product, match_type: 'closest_specs'|'same_type_fallback'}|null
     */
    private function closestSharedHostingMatch(Service $service, Collection $candidates): ?array
    {
        $source = $this->sharedHostingSpecs($service);
        $keys = ['disk_quota', 'bandwidth_quota', 'num_databases'];

        return $this->pickClosestBySpecs($candidates, $source, $keys, function (ResellerProduct $listing) {
            $product = $this->productResolver->resolve($listing);
            if (! $product) {
                return null;
            }

            if ($listing->usesDirectAdminPackage()) {
                return [
                    'listing' => $listing,
                    'product' => $product,
                    'specs' => [
                        'disk_quota' => 0.0,
                        'bandwidth_quota' => 0.0,
                        'num_databases' => 0,
                    ],
                ];
            }

            $package = $listing->adminProduct?->directAdminPackage;
            if (! $package) {
                return null;
            }

            return [
                'listing' => $listing,
                'product' => $product,
                'specs' => [
                    'disk_quota' => (float) $package->disk_quota,
                    'bandwidth_quota' => (float) $package->bandwidth_quota,
                    'num_databases' => (int) $package->num_databases,
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, ResellerProduct>  $candidates
     * @return array{listing: ResellerProduct, product: Product, match_type: 'closest_specs'|'same_type_fallback'}|null
     */
    private function closestContainerMatch(Service $service, Collection $candidates): ?array
    {
        $source = $this->containerSpecsFromService($service);
        $keys = ['cpu', 'memory_mb', 'disk_gb'];

        return $this->pickClosestBySpecs($candidates, $source, $keys, function (ResellerProduct $listing) {
            $product = $this->productResolver->resolve($listing);
            if (! $product) {
                return null;
            }

            return [
                'listing' => $listing,
                'product' => $product,
                'specs' => $this->containerSpecsFromListing($listing, $product),
            ];
        });
    }

    /**
     * @param  Collection<int, ResellerProduct>  $candidates
     * @return array{listing: ResellerProduct, product: Product, match_type: 'same_type_fallback'}|null
     */
    private function closestGenericMatch(Service $service, Collection $candidates): ?array
    {
        $sourceOrder = (int) ($service->product?->order ?? 0);
        $sourcePrice = (float) ($service->product?->monthly_price ?? 0);

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($candidates as $listing) {
            $product = $this->productResolver->resolve($listing);
            if (! $product) {
                continue;
            }

            $distance = abs((int) ($product->order ?? 0) - $sourceOrder) * 100
                + abs((float) ($product->monthly_price ?? 0) - $sourcePrice);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = [
                    'listing' => $listing,
                    'product' => $product,
                    'match_type' => 'same_type_fallback',
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, float|int>  $source
     * @param  list<string>  $keys
     * @param  callable(ResellerProduct): ?array{listing: ResellerProduct, product: Product, specs: array<string, float|int>}  $mapper
     * @return array{listing: ResellerProduct, product: Product, match_type: 'closest_specs'|'same_type_fallback'}|null
     */
    private function pickClosestBySpecs(Collection $candidates, array $source, array $keys, callable $mapper): ?array
    {
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($candidates as $listing) {
            $mapped = $mapper($listing);
            if (! $mapped) {
                continue;
            }

            $distance = $this->specDistance($source, $mapped['specs'], $keys);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = [
                    'listing' => $mapped['listing'],
                    'product' => $mapped['product'],
                    'match_type' => 'closest_specs',
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, float|int>  $source
     * @param  array<string, float|int>  $target
     * @param  list<string>  $keys
     */
    private function specDistance(array $source, array $target, array $keys): float
    {
        $distance = 0.0;

        foreach ($keys as $key) {
            $sourceValue = (float) ($source[$key] ?? 0);
            $targetValue = (float) ($target[$key] ?? 0);

            if ($targetValue < $sourceValue) {
                $distance += ($sourceValue - $targetValue) * 1000;
            } else {
                $distance += $targetValue - $sourceValue;
            }
        }

        return $distance;
    }

    /**
     * @return array{disk_quota: float, bandwidth_quota: float, num_databases: int}
     */
    private function sharedHostingSpecs(Service $service): array
    {
        $package = $service->product?->directAdminPackage;

        return [
            'disk_quota' => (float) ($package?->disk_quota ?? 0),
            'bandwidth_quota' => (float) ($package?->bandwidth_quota ?? 0),
            'num_databases' => (int) ($package?->num_databases ?? 0),
        ];
    }

    /**
     * @return array{cpu: float, memory_mb: int, disk_gb: float}
     */
    private function containerSpecsFromService(Service $service): array
    {
        $product = $service->product;
        if (! $product) {
            return ['cpu' => 0.0, 'memory_mb' => 0, 'disk_gb' => 0.0];
        }

        $limits = $product->getIncludedContainerLimits(
            $product->containerTemplate,
            $service->containerDeployment,
        );

        return [
            'cpu' => (float) $limits['cpu'],
            'memory_mb' => (int) $limits['memory_mb'],
            'disk_gb' => (float) $limits['disk_gb'],
        ];
    }

    /**
     * @return array{cpu: float, memory_mb: int, disk_gb: float}
     */
    private function containerSpecsFromListing(ResellerProduct $listing, Product $product): array
    {
        $limits = $listing->containerResourceLimits();
        if ($limits['cpu'] !== null || $limits['memory_mb'] !== null || $limits['disk_gb'] !== null) {
            return [
                'cpu' => (float) ($limits['cpu'] ?? 0),
                'memory_mb' => (int) ($limits['memory_mb'] ?? 0),
                'disk_gb' => (float) ($limits['disk_gb'] ?? 0),
            ];
        }

        $included = $product->getIncludedContainerLimits($product->containerTemplate);

        return [
            'cpu' => (float) $included['cpu'],
            'memory_mb' => (int) $included['memory_mb'],
            'disk_gb' => (float) $included['disk_gb'],
        ];
    }
}
