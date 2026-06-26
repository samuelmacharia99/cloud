<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;

class PublicApiCatalogSerializer
{
    public function __construct(
        private ServerProductConfigService $serverConfig,
    ) {}

    /**
     * @param  iterable<string>  $allowedExtensions
     * @return array{name: string, extension: string, full_domain: string}|null
     */
    public function parseTransferDomain(string $fullDomain, iterable $allowedExtensions): ?array
    {
        $fullDomain = strtolower(trim(str_replace(['www.', 'https://', 'http://'], '', $fullDomain)));

        if ($fullDomain === '' || ! str_contains($fullDomain, '.')) {
            return null;
        }

        $extensions = collect($allowedExtensions)
            ->sortByDesc(fn (string $extension) => strlen($extension))
            ->values();

        foreach ($extensions as $extension) {
            if (! str_ends_with($fullDomain, $extension)) {
                continue;
            }

            $name = substr($fullDomain, 0, -strlen($extension));

            if ($name === '' || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
                continue;
            }

            return [
                'name' => $name,
                'extension' => $extension,
                'full_domain' => $name.$extension,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPlatformProduct(Product $product): array
    {
        $row = $this->baseProductRow(
            id: (int) $product->getKey(),
            name: $product->name,
            description: $product->description,
            type: $product->type,
            category: $product->category,
            monthlyPrice: (float) ($product->monthly_price ?? 0),
            yearlyPrice: $product->yearly_price !== null ? (float) $product->yearly_price : null,
            setupFee: (float) ($product->setup_fee ?? 0),
            features: $product->features ?? [],
        );

        if (Product::isServerType($product->type)) {
            $row['configuration'] = $this->serverConfiguration($product);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatResellerProduct(ResellerProduct $listing): array
    {
        $adminProduct = $listing->adminProduct;
        $type = (string) ($listing->type ?? $adminProduct?->type ?? '');

        $row = $this->baseProductRow(
            id: (int) $listing->getKey(),
            name: $listing->name,
            description: $listing->description,
            type: $type,
            category: $adminProduct?->category,
            monthlyPrice: (float) ($listing->monthly_price ?? 0),
            yearlyPrice: $listing->yearly_price !== null ? (float) $listing->yearly_price : null,
            setupFee: (float) ($listing->setup_fee ?? 0),
            features: $listing->features ?? [],
        );

        if ($adminProduct && Product::isServerType($type)) {
            $row['admin_product_id'] = $adminProduct->id;
            $row['configuration'] = $this->serverConfiguration($adminProduct, $listing);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatResellerPackage(ResellerPackage $package): array
    {
        $amounts = app(ResellerPackageSubscriptionService::class)->calculateAmounts((float) $package->price);

        return [
            'id' => $package->id,
            'name' => $package->name,
            'description' => $package->description,
            'billing_cycle' => $package->billing_cycle,
            'price' => (float) $package->price,
            'subtotal' => $amounts['subtotal'],
            'tax' => $amounts['tax'],
            'total' => $amounts['total'],
            'currency' => 'KES',
            'max_services' => $package->max_services,
            'max_users' => $package->max_users,
            'disk_pool_gb' => $package->disk_pool_gb,
            'disk_overage_rate' => (float) ($package->disk_overage_rate ?? 0),
            'features' => [
                'Up to '.$package->max_users.' customers',
                'Up to '.$package->max_services.' active services',
                number_format($package->disk_pool_gb).' GB disk pool',
            ],
        ];
    }

    /**
     * Validate and normalize server cart fields. Returns [] for non-server products.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function serverCartFields(Product $product, array $item, ?ResellerProduct $listing = null): ?array
    {
        if (! Product::isServerType($product->type)) {
            return [];
        }

        $locations = $this->serverConfig->locations($product);
        if ($locations === []) {
            return null;
        }

        $locationKey = trim((string) ($item['location_key'] ?? ''));
        if ($locationKey === '') {
            $locationKey = (string) ($locations[0]['key'] ?? '');
        }

        $location = $this->serverConfig->location($product, $locationKey);
        if (! $location) {
            return null;
        }

        $maxIpCount = (int) config('server_options.max_ip_count', 8);
        $ipCount = (int) ($item['ip_count'] ?? 1);
        if ($ipCount < 1 || $ipCount > $maxIpCount) {
            return null;
        }

        $validOs = array_keys(config('server_options.linux_distributions', []));
        $operatingSystem = (string) ($item['operating_system'] ?? '');
        if ($operatingSystem === '' || ! in_array($operatingSystem, $validOs, true)) {
            return null;
        }

        $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');
        if (! in_array($billingCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)) {
            return null;
        }

        if ($billingCycle === 'annual') {
            $resolved = $this->serverConfig->resolvedLocationPrices($product, $location, $listing, false);
            if ((float) $resolved['yearly'] <= 0) {
                return null;
            }
        }

        try {
            $this->serverConfig->resolveOrderPricing(
                $product,
                $listing,
                $locationKey,
                $ipCount,
                $billingCycle,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }

        return [
            'location_key' => $locationKey,
            'location_name' => $location['name'] ?? null,
            'location_city' => $location['city'] ?? null,
            'ip_count' => $ipCount,
            'operating_system' => $operatingSystem,
        ];
    }

    /**
     * @param  list<mixed>  $features
     * @return array<string, mixed>
     */
    private function baseProductRow(
        int $id,
        ?string $name,
        ?string $description,
        ?string $type,
        ?string $category,
        float $monthlyPrice,
        ?float $yearlyPrice,
        float $setupFee,
        array $features,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'category' => $category,
            'monthly_price' => $monthlyPrice,
            'yearly_price' => $yearlyPrice,
            'setup_fee' => $setupFee,
            'currency' => 'KES',
            'billing_cycles' => ['monthly', 'quarterly', 'semi-annual', 'annual'],
            'features' => $features,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serverConfiguration(Product $product, ?ResellerProduct $listing = null): array
    {
        $config = $this->serverConfig->config($product);
        $locations = [];

        foreach ($this->serverConfig->locations($product) as $location) {
            $locationKey = (string) ($location['key'] ?? '');
            $prices = [];

            foreach (['monthly', 'quarterly', 'semi-annual', 'annual'] as $billingCycle) {
                try {
                    $pricing = $this->serverConfig->resolveOrderPricing(
                        $product,
                        $listing,
                        $locationKey,
                        1,
                        $billingCycle,
                    );
                    $prices[$billingCycle] = (float) $pricing['unit_price'];
                } catch (\InvalidArgumentException) {
                    $prices[$billingCycle] = null;
                }
            }

            try {
                $setupPricing = $this->serverConfig->resolveOrderPricing(
                    $product,
                    $listing,
                    $locationKey,
                    1,
                    'monthly',
                );
                $setupFee = (float) $setupPricing['setup_fee'];
            } catch (\InvalidArgumentException) {
                $setupFee = null;
            }

            $locations[] = [
                'key' => $locationKey,
                'name' => $location['name'] ?? '',
                'city' => $location['city'] ?? null,
                'prices' => array_merge($prices, [
                    'setup_fee' => $setupFee,
                    'currency' => 'KES',
                ]),
            ];
        }

        $ipOptions = array_map(static function (array $option): array {
            return [
                'ip_count' => (int) ($option['ips'] ?? 0),
                'monthly_addon' => (float) ($option['monthly_addon'] ?? 0),
                'setup_addon' => (float) ($option['setup_addon'] ?? 0),
                'label' => (string) ($option['label'] ?? ''),
            ];
        }, $this->serverConfig->ipOptions($product));

        $operatingSystems = [];
        foreach (config('server_options.linux_distributions', []) as $key => $label) {
            $operatingSystems[] = [
                'key' => $key,
                'label' => $label,
            ];
        }

        return [
            'specs' => [
                'cpu_cores' => $config['cpu_cores'] ?? null,
                'ram_gb' => $config['ram_gb'] ?? null,
                'storage_gb' => $config['storage_gb'] ?? null,
                'storage_type' => $config['storage_type'] ?? null,
                'raid' => $config['raid'] ?? null,
                'bandwidth_tb' => $config['bandwidth_tb'] ?? null,
                'managed' => (bool) ($config['managed'] ?? false),
                'money_back_days' => $config['money_back_days'] ?? null,
            ],
            'spec_lines' => $this->serverConfig->specLines($product),
            'locations' => $locations,
            'ip_options' => $ipOptions,
            'operating_systems' => $operatingSystems,
            'max_ip_count' => (int) config('server_options.max_ip_count', 8),
        ];
    }
}
