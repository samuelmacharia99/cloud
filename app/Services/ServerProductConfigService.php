<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
use Illuminate\Support\Str;

class ServerProductConfigService
{
    /**
     * @return list<string>
     */
    public function specLines(Product $product): array
    {
        $config = $this->config($product);
        $lines = [];

        if ($config['cpu_cores'] ?? null) {
            $lines[] = $config['cpu_cores'].' CPU '.Str::plural('Core', (int) $config['cpu_cores']);
        }

        if ($config['ram_gb'] ?? null) {
            $lines[] = $config['ram_gb'].' GB RAM';
        }

        if ($config['storage_gb'] ?? null) {
            $storage = $config['storage_gb'].' GB';
            if ($config['storage_type'] ?? null) {
                $storage .= ' '.$config['storage_type'];
            }
            $lines[] = $storage.' Storage';
        }

        if ($config['raid'] ?? null) {
            $lines[] = 'RAID: '.$config['raid'];
        }

        if ($config['bandwidth_tb'] ?? null) {
            $lines[] = $config['bandwidth_tb'].' TB Bandwidth';
        }

        if ($this->hasStructuredConfig($config)) {
            $lines[] = '1 Dedicated IP included';
        }

        if ($config['managed'] ?? false) {
            $lines[] = 'Fully Managed Hosting';
        }

        if ($config['money_back_days'] ?? null) {
            $lines[] = $config['money_back_days'].'-Day Money-Back';
        }

        if ($lines === [] && ! empty($config['legacy_specs'])) {
            $legacy = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $config['legacy_specs'])));
            foreach (preg_split('/\r\n|\r|\n/', $legacy) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function locations(Product $product): array
    {
        $locations = $this->config($product)['locations'] ?? [];

        if ($locations !== []) {
            return array_values($locations);
        }

        if ($product->monthly_price || $product->yearly_price) {
            return [[
                'key' => 'default',
                'name' => $this->config($product)['legacy_location'] ?? 'Default',
                'monthly_surcharge' => 0.0,
                'yearly_surcharge' => 0.0,
                'wholesale_monthly_surcharge' => 0.0,
                'wholesale_yearly_surcharge' => 0.0,
                'setup_surcharge' => 0.0,
            ]];
        }

        return [];
    }

    public function location(Product $product, string $key): ?array
    {
        foreach ($this->locations($product) as $location) {
            if (($location['key'] ?? '') === $key) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Base product price + datacenter surcharge (+ optional reseller listing base).
     *
     * @return array{monthly: float, yearly: float, setup: float}
     */
    public function resolvedLocationPrices(
        Product $product,
        array $location,
        ?ResellerProduct $listing = null,
        bool $useWholesale = false,
    ): array {
        if ($listing && ! $useWholesale) {
            $monthlyBase = (float) ($listing->monthly_price ?? 0);
            $yearlyBase = (float) ($listing->yearly_price ?? 0);
            if ($yearlyBase <= 0 && $monthlyBase > 0) {
                $yearlyBase = $monthlyBase * 12;
            }

            return [
                'monthly' => $monthlyBase + $this->locationSurcharge($location, $product, 'monthly', false),
                'yearly' => $yearlyBase + $this->locationSurcharge($location, $product, 'yearly', false),
                'setup' => (float) ($listing->setup_fee ?? 0) + $this->locationSurcharge($location, $product, 'setup', false),
            ];
        }

        $monthlyBase = $useWholesale
            ? (float) ($product->wholesale_monthly_price ?? 0)
            : (float) ($product->monthly_price ?? 0);

        $yearlyBase = $useWholesale
            ? (float) ($product->wholesale_yearly_price ?? 0)
            : (float) ($product->yearly_price ?? 0);

        if ($yearlyBase <= 0 && $monthlyBase > 0) {
            $yearlyBase = $monthlyBase * 12;
        }

        return [
            'monthly' => $monthlyBase + $this->locationSurcharge($location, $product, 'monthly', $useWholesale),
            'yearly' => $yearlyBase + $this->locationSurcharge($location, $product, 'yearly', $useWholesale),
            'setup' => (float) ($product->setup_fee ?? 0) + $this->locationSurcharge($location, $product, 'setup', $useWholesale),
        ];
    }

    /**
     * @return array<int, array{ips: int, monthly_addon: float, setup_addon: float, label: string}>
     */
    public function ipOptions(Product $product): array
    {
        $included = 1;
        $max = (int) config('server_options.max_ip_count', 8);
        $monthlyPerExtra = $this->additionalIpMonthly($product);
        $setupPerExtra = $this->additionalIpSetup($product);
        $options = [];

        for ($ips = $included; $ips <= $max; $ips++) {
            $extraIps = max(0, $ips - $included);
            $monthlyAddon = $extraIps * $monthlyPerExtra;
            $setupAddon = $extraIps * $setupPerExtra;
            $label = $ips.' '.Str::plural('IP', $ips);

            if ($extraIps > 0 && $monthlyPerExtra > 0) {
                $label .= ' (+'.number_format($monthlyAddon, 0).'/mo)';
            }

            $options[] = [
                'ips' => $ips,
                'monthly_addon' => $monthlyAddon,
                'setup_addon' => $setupAddon,
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @deprecated Use ipOptions() — IP pricing is product-level, not per location.
     *
     * @return array<int, array{ips: int, monthly_addon: float, setup_addon: float, label: string}>
     */
    public function ipOptionsForLocation(array $location, Product $product): array
    {
        return $this->ipOptions($product);
    }

    /**
     * @param  array<string, mixed>  $cartItem
     * @return array{unit_price: float, setup_fee: float}
     */
    public function priceForCartItem(Product $product, array $cartItem, ?ResellerProduct $listing = null): array
    {
        if (! Product::isServerType($product->type)) {
            return [
                'unit_price' => $this->legacyProductPrice($product, (string) ($cartItem['billing_cycle'] ?? 'monthly')),
                'setup_fee' => (float) ($listing?->setup_fee ?? $product->setup_fee ?? 0),
            ];
        }

        $locationKey = (string) ($cartItem['location_key'] ?? '');
        if ($locationKey === '') {
            $locations = $this->locations($product);
            $locationKey = (string) ($locations[0]['key'] ?? 'default');
        }

        $pricing = $this->resolveOrderPricing(
            $product,
            $listing,
            $locationKey,
            (int) ($cartItem['ip_count'] ?? 1),
            (string) ($cartItem['billing_cycle'] ?? 'monthly'),
        );

        return [
            'unit_price' => $pricing['unit_price'],
            'setup_fee' => $pricing['setup_fee'],
        ];
    }

    /**
     * @return array{
     *     unit_price: float,
     *     setup_fee: float,
     *     location: array<string, mixed>|null,
     *     ip_count: int,
     *     monthly_addon: float,
     *     setup_addon: float
     * }
     */
    public function resolveOrderPricing(
        Product $product,
        ?ResellerProduct $listing,
        string $locationKey,
        int $ipCount,
        string $billingCycle,
        bool $useWholesale = false,
    ): array {
        $location = $this->location($product, $locationKey);
        if (! $location) {
            throw new \InvalidArgumentException('Invalid datacenter location selected.');
        }

        $ipAddon = $this->ipAddonForCount($product, $ipCount);
        $ipMonthlyAddon = (float) ($ipAddon['monthly_addon'] ?? 0);
        $ipSetupAddon = (float) ($ipAddon['setup_addon'] ?? 0);
        $resolved = $this->resolvedLocationPrices($product, $location, $listing, $useWholesale);

        $unitPrice = match ($billingCycle) {
            'annual' => $resolved['yearly'] + ($ipMonthlyAddon * 12),
            'semi-annual' => ($resolved['monthly'] * 6) + ($ipMonthlyAddon * 6),
            'quarterly' => ($resolved['monthly'] * 3) + ($ipMonthlyAddon * 3),
            default => $resolved['monthly'] + $ipMonthlyAddon,
        };

        return [
            'unit_price' => round($unitPrice, 2),
            'setup_fee' => round($resolved['setup'] + $ipSetupAddon, 2),
            'location' => $location,
            'ip_count' => $ipCount,
            'monthly_addon' => $ipMonthlyAddon,
            'setup_addon' => $ipSetupAddon,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeFromRequest(array $input, string $type): array
    {
        $config = [
            'cpu_cores' => $this->nullableInt($input['cpu_cores'] ?? null),
            'ram_gb' => $this->nullableInt($input['ram_gb'] ?? null),
            'storage_gb' => $this->nullableInt($input['storage_gb'] ?? null),
            'storage_type' => $this->nullableString($input['storage_type'] ?? null),
            'raid' => $type === 'dedicated_server' ? $this->nullableString($input['raid'] ?? null) : null,
            'bandwidth_tb' => $this->nullableFloat($input['bandwidth_tb'] ?? null),
            'included_ips' => 1,
            'additional_ip_monthly' => (float) ($input['additional_ip_monthly'] ?? 0),
            'additional_ip_setup' => (float) ($input['additional_ip_setup'] ?? 0),
            'managed' => filter_var($input['managed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'money_back_days' => $this->nullableInt($input['money_back_days'] ?? null),
            'locations' => [],
        ];

        foreach ($input['locations'] ?? [] as $index => $location) {
            if (! is_array($location)) {
                continue;
            }

            $name = trim((string) ($location['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = trim((string) ($location['key'] ?? ''));
            if ($key === '') {
                $key = Str::slug($name) ?: 'location-'.($index + 1);
            }

            $config['locations'][] = [
                'key' => $key,
                'name' => $name,
                'city' => $this->nullableString($location['city'] ?? null),
                'monthly_surcharge' => (float) ($location['monthly_surcharge'] ?? $location['monthly_price'] ?? 0),
                'yearly_surcharge' => (float) ($location['yearly_surcharge'] ?? $location['yearly_price'] ?? 0),
                'wholesale_monthly_surcharge' => (float) ($location['wholesale_monthly_surcharge'] ?? $location['wholesale_monthly_price'] ?? 0),
                'wholesale_yearly_surcharge' => (float) ($location['wholesale_yearly_surcharge'] ?? $location['wholesale_yearly_price'] ?? 0),
                'setup_surcharge' => (float) ($location['setup_surcharge'] ?? $location['setup_fee'] ?? 0),
            ];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(Product $product): array
    {
        $limits = $product->resource_limits ?? [];
        if (! is_array($limits)) {
            return [];
        }

        if ($this->hasStructuredConfig($limits)) {
            return $limits;
        }

        return [
            'legacy_specs' => $limits['specs'] ?? null,
            'legacy_location' => $limits['location'] ?? null,
            'locations' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function locationSurcharge(array $location, Product $product, string $type, bool $wholesale): float
    {
        $newKey = match ($type) {
            'monthly' => $wholesale ? 'wholesale_monthly_surcharge' : 'monthly_surcharge',
            'yearly' => $wholesale ? 'wholesale_yearly_surcharge' : 'yearly_surcharge',
            'setup' => 'setup_surcharge',
            default => null,
        };

        if ($newKey && array_key_exists($newKey, $location)) {
            return (float) $location[$newKey];
        }

        return $this->legacyLocationSurcharge($location, $product, $type, $wholesale);
    }

    /**
     * Convert legacy full location prices into surcharges when old data is still stored.
     *
     * @param  array<string, mixed>  $location
     */
    private function legacyLocationSurcharge(array $location, Product $product, string $type, bool $wholesale): float
    {
        $legacyKey = match ($type) {
            'monthly' => $wholesale ? 'wholesale_monthly_price' : 'monthly_price',
            'yearly' => $wholesale ? 'wholesale_yearly_price' : 'yearly_price',
            'setup' => 'setup_fee',
            default => null,
        };

        if (! $legacyKey || ! array_key_exists($legacyKey, $location)) {
            return 0.0;
        }

        $legacyValue = (float) $location[$legacyKey];
        $productBase = match ($type) {
            'monthly' => $wholesale ? (float) ($product->wholesale_monthly_price ?? 0) : (float) ($product->monthly_price ?? 0),
            'yearly' => $wholesale ? (float) ($product->wholesale_yearly_price ?? 0) : (float) ($product->yearly_price ?? 0),
            'setup' => (float) ($product->setup_fee ?? 0),
            default => 0.0,
        };

        return max(0.0, $legacyValue - $productBase);
    }

    private function additionalIpMonthly(Product $product): float
    {
        $config = $this->config($product);

        if (array_key_exists('additional_ip_monthly', $config)) {
            return (float) $config['additional_ip_monthly'];
        }

        return $this->legacyAdditionalIpMonthly($config);
    }

    private function additionalIpSetup(Product $product): float
    {
        $config = $this->config($product);

        if (array_key_exists('additional_ip_setup', $config)) {
            return (float) $config['additional_ip_setup'];
        }

        return $this->legacyAdditionalIpSetup($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function legacyAdditionalIpMonthly(array $config): float
    {
        foreach ($config['locations'] ?? [] as $location) {
            foreach ($location['ip_tiers'] ?? [] as $tier) {
                if ((int) ($tier['ips'] ?? 0) === 2) {
                    return (float) ($tier['monthly_addon'] ?? 0);
                }
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function legacyAdditionalIpSetup(array $config): float
    {
        foreach ($config['locations'] ?? [] as $location) {
            foreach ($location['ip_tiers'] ?? [] as $tier) {
                if ((int) ($tier['ips'] ?? 0) === 2) {
                    return (float) ($tier['setup_addon'] ?? 0);
                }
            }
        }

        return 0.0;
    }

    /**
     * @return array{ips: int, monthly_addon: float, setup_addon: float}
     */
    private function ipAddonForCount(Product $product, int $ipCount): array
    {
        foreach ($this->ipOptions($product) as $option) {
            if ((int) $option['ips'] === $ipCount) {
                return $option;
            }
        }

        throw new \InvalidArgumentException('Invalid IP count selected.');
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function hasStructuredConfig(array $limits): bool
    {
        return array_key_exists('cpu_cores', $limits)
            || array_key_exists('locations', $limits)
            || array_key_exists('ram_gb', $limits);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function legacyProductPrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }
}
