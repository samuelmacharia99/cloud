<?php

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\ResellerDirectAdminService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pull a reseller's DirectAdmin user packages into their Talksasa catalog
 * as Application Hosting listings, keeping the names and retail prices they already sell.
 */
class DaResellerPackageImportService
{
    public function __construct(
        private ResellerDirectAdminService $directAdmin,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     listings: Collection<int, ResellerProduct>,
     *     packages: list<array<string, mixed>>,
     *     error: ?string
     * }
     */
    public function import(User $reseller, ?User $actor = null): array
    {
        if (! $reseller->is_reseller) {
            throw new InvalidArgumentException('Only a reseller book can import DirectAdmin packages.');
        }

        $listed = $this->directAdmin->listAssignablePackages($reseller);
        $packages = $listed['packages'] ?? [];
        if ($packages === []) {
            throw new InvalidArgumentException(
                $listed['error'] ?: 'No DirectAdmin packages were found for this reseller. Create packages on DirectAdmin first.'
            );
        }

        $created = 0;
        $updated = 0;
        $listings = collect();

        foreach ($packages as $package) {
            $name = trim((string) ($package['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $before = $this->findListing($reseller, $name);
            $listing = $this->upsertListing($reseller, $package, $before);
            $listings->push($listing);
            if ($before) {
                $updated++;
            } else {
                $created++;
            }
        }

        AdminActivityService::log(
            'reseller.da_offramp_import_packages',
            'Imported DirectAdmin packages into catalog for '.$reseller->name,
            $reseller,
            [
                'created' => $created,
                'updated' => $updated,
                'package_names' => $listings->pluck('direct_admin_package_name')->all(),
            ],
        );

        return [
            'created' => $created,
            'updated' => $updated,
            'listings' => $listings->values(),
            'packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<int, array{
     *     service: Service,
     *     da_package: string,
     *     listing: ?ResellerProduct,
     *     engine: ?Product,
     *     retail: ?float,
     *     needs_price: bool
     * }>
     */
    public function mappingForServices(User $reseller, Collection $services): Collection
    {
        return $services->map(function (Service $service) use ($reseller): array {
            $resolved = $this->resolveForService($reseller, $service);

            return [
                'service' => $service,
                'da_package' => $resolved['da_package'],
                'listing' => $resolved['listing'],
                'engine' => $resolved['engine'],
                'retail' => $resolved['retail'],
                'needs_price' => $resolved['needs_price'],
            ];
        })->values();
    }

    /**
     * @return array{da_package: string, listing: ?ResellerProduct, engine: ?Product, retail: ?float, needs_price: bool}
     */
    public function resolveForService(User $reseller, Service $service, ?Product $fallbackEngine = null): array
    {
        $daPackage = $this->serviceDaPackageName($service);
        $listing = $this->findListing($reseller, $daPackage)
            ?? $this->listingFromService($service, $reseller);
        $engine = $listing?->adminProduct;
        if (! $engine || $engine->type !== 'container_hosting') {
            $engine = $fallbackEngine;
        }
        if ($engine && $engine->type !== 'container_hosting') {
            $engine = null;
        }

        $retail = $service->custom_price !== null
            ? (float) $service->custom_price
            : ($listing ? $listing->priceForBillingCycle((string) ($service->billing_cycle ?? 'monthly')) : null);

        return [
            'da_package' => $daPackage,
            'listing' => $listing,
            'engine' => $engine,
            'retail' => $retail,
            'needs_price' => $listing !== null && (float) ($listing->monthly_price ?? 0) <= 0 && (float) ($listing->yearly_price ?? 0) <= 0 && $service->custom_price === null,
        ];
    }

    /**
     * @return array{da_package: string, listing: ?ResellerProduct, engine: ?Product, retail: ?float, needs_price: bool}
     */
    public function resolveForPackageName(User $reseller, string $packageName, ?Product $fallbackEngine = null): array
    {
        $daPackage = trim($packageName);
        $listing = $this->findListing($reseller, $daPackage);
        $engine = $listing?->adminProduct;
        if (! $engine || $engine->type !== 'container_hosting') {
            $engine = $fallbackEngine;
        }
        if ($engine && $engine->type !== 'container_hosting') {
            $engine = null;
        }

        $retail = $listing ? $listing->priceForBillingCycle('monthly') : null;

        return [
            'da_package' => $daPackage,
            'listing' => $listing,
            'engine' => $engine,
            'retail' => $retail,
            'needs_price' => $listing !== null && (float) ($listing->monthly_price ?? 0) <= 0 && (float) ($listing->yearly_price ?? 0) <= 0,
        ];
    }

    public function serviceDaPackageName(Service $service): string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        foreach (['package_name', 'package', 'da_package'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $service->loadMissing('product.directAdminPackage');

        return trim((string) ($service->product?->directAdminPackage?->name ?? ''));
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function upsertListing(User $reseller, array $package, ?ResellerProduct $existing): ResellerProduct
    {
        $name = trim((string) $package['name']);
        $diskGb = (float) ($package['disk_quota'] ?? 0);
        $engine = $this->nearestEngine($diskGb);
        if (! $engine) {
            throw new InvalidArgumentException(
                'Create at least one active Application Hosting product so DA packages can be mapped to a container size.'
            );
        }

        $prices = $existing
            ? [
                'monthly_price' => $existing->monthly_price,
                'yearly_price' => $existing->yearly_price,
                'setup_fee' => $existing->setup_fee,
            ]
            : $this->inferPrices($reseller, $name);

        $limits = $engine->getIncludedContainerLimits($engine->containerTemplate);
        $attributes = [
            'name' => $existing?->name ?: $name,
            'description' => $existing?->description ?: (string) ($package['description'] ?? ''),
            'type' => 'container_hosting',
            'direct_admin_package_name' => $name,
            'product_id' => $engine->id,
            'container_template_id' => $engine->container_template_id,
            'resource_limits' => [
                'cpu' => $limits['cpu'],
                'memory_mb' => $limits['memory_mb'],
                'disk_gb' => $diskGb > 0 ? $diskGb : $limits['disk_gb'],
            ],
            'monthly_price' => $prices['monthly_price'],
            'yearly_price' => $prices['yearly_price'],
            'setup_fee' => $prices['setup_fee'] ?? 0,
            'is_active' => $existing?->is_active ?? true,
        ];

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            return $existing->fresh(['adminProduct']) ?? $existing;
        }

        $listing = new ResellerProduct($attributes);
        $listing->reseller_id = $reseller->id;
        $listing->save();

        return $listing->fresh(['adminProduct']) ?? $listing;
    }

    private function findListing(User $reseller, string $packageName): ?ResellerProduct
    {
        $packageName = strtolower(trim($packageName));
        if ($packageName === '') {
            return null;
        }

        return ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where(function ($query) use ($packageName) {
                $query->whereRaw('LOWER(direct_admin_package_name) = ?', [$packageName])
                    ->orWhereRaw('LOWER(name) = ?', [$packageName]);
            })
            ->orderByRaw("CASE WHEN type = 'container_hosting' THEN 0 ELSE 1 END")
            ->first();
    }

    private function listingFromService(Service $service, User $reseller): ?ResellerProduct
    {
        $id = (int) ($service->reseller_product_id ?? ($service->service_meta['reseller_product_id'] ?? 0));
        if ($id <= 0) {
            return null;
        }

        return ResellerProduct::query()
            ->whereKey($id)
            ->where('reseller_id', $reseller->id)
            ->first();
    }

    private function nearestEngine(float $diskGb): ?Product
    {
        $products = Product::query()
            ->where('type', 'container_hosting')
            ->where('is_active', true)
            ->with('containerTemplate')
            ->orderBy('order')
            ->orderBy('monthly_price')
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        if ($diskGb <= 0) {
            return $products->first();
        }

        return $products
            ->sortBy(function (Product $product) use ($diskGb): float {
                $limits = $product->getIncludedContainerLimits($product->containerTemplate);

                return abs(((float) $limits['disk_gb']) - $diskGb);
            })
            ->first();
    }

    /**
     * @return array{monthly_price: float, yearly_price: float, setup_fee: float}
     */
    private function inferPrices(User $reseller, string $packageName): array
    {
        $key = strtolower(trim($packageName));
        $fromServices = Service::query()
            ->where('reseller_id', $reseller->id)
            ->whereNotNull('custom_price')
            ->where('custom_price', '>', 0)
            ->get()
            ->first(function (Service $service) use ($key): bool {
                return strtolower($this->serviceDaPackageName($service)) === $key;
            });

        if ($fromServices) {
            $monthly = (float) $fromServices->custom_price;
            if (($fromServices->billing_cycle ?? 'monthly') === 'annual' || ($fromServices->billing_cycle ?? '') === 'yearly') {
                return [
                    'monthly_price' => round($monthly / 12, 2),
                    'yearly_price' => $monthly,
                    'setup_fee' => 0,
                ];
            }

            return [
                'monthly_price' => $monthly,
                'yearly_price' => round($monthly * 12, 2),
                'setup_fee' => 0,
            ];
        }

        return [
            'monthly_price' => 0,
            'yearly_price' => 0,
            'setup_fee' => 0,
        ];
    }
}
