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

        if ($config['included_ips'] ?? null) {
            $lines[] = $config['included_ips'].' Dedicated '.Str::plural('IP', (int) $config['included_ips']);
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
                'monthly_price' => (float) $product->monthly_price,
                'yearly_price' => (float) ($product->yearly_price ?? 0),
                'wholesale_monthly_price' => (float) ($product->wholesale_monthly_price ?? 0),
                'wholesale_yearly_price' => (float) ($product->wholesale_yearly_price ?? 0),
                'setup_fee' => (float) ($product->setup_fee ?? 0),
                'ip_tiers' => $this->defaultIpTiers((int) ($this->config($product)['included_ips'] ?? 1)),
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
     * @return array<int, array{ips: int, monthly_addon: float, setup_addon: float, label: string}>
     */
    public function ipOptionsForLocation(array $location, Product $product): array
    {
        $included = (int) ($this->config($product)['included_ips'] ?? 1);
        $tiers = $location['ip_tiers'] ?? $this->defaultIpTiers($included);
        $options = [];

        foreach ($tiers as $tier) {
            $ips = (int) ($tier['ips'] ?? 0);
            if ($ips < 1) {
                continue;
            }

            $monthlyAddon = (float) ($tier['monthly_addon'] ?? 0);
            $setupAddon = (float) ($tier['setup_addon'] ?? 0);
            $label = $ips.' '.Str::plural('IP', $ips);
            if ($monthlyAddon > 0) {
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

        $ipTier = $this->ipTierForCount($location, $ipCount, $product);
        $monthlyAddon = (float) ($ipTier['monthly_addon'] ?? 0);
        $setupAddon = (float) ($ipTier['setup_addon'] ?? 0);

        if ($listing && ! $useWholesale) {
            $base = $listing->priceForBillingCycle($billingCycle === 'annual' ? 'annual' : 'monthly');
            $unitPrice = $billingCycle === 'annual'
                ? $base + ($monthlyAddon * 12)
                : $base + $monthlyAddon;
            $setupFee = (float) ($listing->setup_fee ?? 0) + $setupAddon;

            return [
                'unit_price' => round($unitPrice, 2),
                'setup_fee' => round($setupFee, 2),
                'location' => $location,
                'ip_count' => $ipCount,
                'monthly_addon' => $monthlyAddon,
                'setup_addon' => $setupAddon,
            ];
        }

        $monthlyBase = $useWholesale
            ? (float) ($location['wholesale_monthly_price'] ?? $product->wholesale_monthly_price ?? 0)
            : (float) ($location['monthly_price'] ?? $product->monthly_price ?? 0);

        $yearlyBase = $useWholesale
            ? (float) ($location['wholesale_yearly_price'] ?? $product->wholesale_yearly_price ?? 0)
            : (float) ($location['yearly_price'] ?? $product->yearly_price ?? ($monthlyBase * 12));

        $setupBase = (float) ($location['setup_fee'] ?? $product->setup_fee ?? 0);

        $unitPrice = match ($billingCycle) {
            'annual' => $yearlyBase + ($monthlyAddon * 12),
            'semi-annual' => ($monthlyBase * 6) + ($monthlyAddon * 6),
            'quarterly' => ($monthlyBase * 3) + ($monthlyAddon * 3),
            default => $monthlyBase + $monthlyAddon,
        };

        return [
            'unit_price' => round($unitPrice, 2),
            'setup_fee' => round($setupBase + $setupAddon, 2),
            'location' => $location,
            'ip_count' => $ipCount,
            'monthly_addon' => $monthlyAddon,
            'setup_addon' => $setupAddon,
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
            'included_ips' => max(1, (int) ($input['included_ips'] ?? 1)),
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

            $ipTiers = [];
            foreach ($location['ip_tiers'] ?? [] as $tier) {
                if (! is_array($tier)) {
                    continue;
                }

                $ips = (int) ($tier['ips'] ?? 0);
                if ($ips < 1) {
                    continue;
                }

                $ipTiers[] = [
                    'ips' => $ips,
                    'monthly_addon' => (float) ($tier['monthly_addon'] ?? 0),
                    'setup_addon' => (float) ($tier['setup_addon'] ?? 0),
                ];
            }

            if ($ipTiers === []) {
                $ipTiers = $this->defaultIpTiers($config['included_ips']);
            }

            usort($ipTiers, fn ($a, $b) => $a['ips'] <=> $b['ips']);

            $config['locations'][] = [
                'key' => $key,
                'name' => $name,
                'city' => $this->nullableString($location['city'] ?? null),
                'monthly_price' => (float) ($location['monthly_price'] ?? 0),
                'yearly_price' => (float) ($location['yearly_price'] ?? 0),
                'wholesale_monthly_price' => (float) ($location['wholesale_monthly_price'] ?? 0),
                'wholesale_yearly_price' => (float) ($location['wholesale_yearly_price'] ?? 0),
                'setup_fee' => (float) ($location['setup_fee'] ?? 0),
                'ip_tiers' => $ipTiers,
            ];
        }

        return $config;
    }

    /**
     * Sync legacy top-level product prices from the first configured location.
     *
     * @param  array<string, mixed>  $config
     * @return array{monthly_price: float|null, yearly_price: float|null, wholesale_monthly_price: float|null, wholesale_yearly_price: float|null, setup_fee: float|null}
     */
    public function syncProductPricesFromConfig(array $config): array
    {
        $first = $config['locations'][0] ?? null;
        if (! $first) {
            return [
                'monthly_price' => null,
                'yearly_price' => null,
                'wholesale_monthly_price' => null,
                'wholesale_yearly_price' => null,
                'setup_fee' => null,
            ];
        }

        return [
            'monthly_price' => (float) ($first['monthly_price'] ?? 0),
            'yearly_price' => (float) ($first['yearly_price'] ?? 0),
            'wholesale_monthly_price' => (float) ($first['wholesale_monthly_price'] ?? 0),
            'wholesale_yearly_price' => (float) ($first['wholesale_yearly_price'] ?? 0),
            'setup_fee' => (float) ($first['setup_fee'] ?? 0),
        ];
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
     * @return array{ips: int, monthly_addon: float, setup_addon: float}
     */
    private function ipTierForCount(array $location, int $ipCount, Product $product): array
    {
        $options = $this->ipOptionsForLocation($location, $product);
        foreach ($options as $option) {
            if ((int) $option['ips'] === $ipCount) {
                return $option;
            }
        }

        throw new \InvalidArgumentException('Invalid IP count for selected location.');
    }

    /**
     * @return list<array{ips: int, monthly_addon: float, setup_addon: float}>
     */
    private function defaultIpTiers(int $includedIps): array
    {
        $max = (int) config('server_options.max_ip_count', 8);
        $tiers = [];

        for ($ips = 1; $ips <= $max; $ips++) {
            $tiers[] = [
                'ips' => $ips,
                'monthly_addon' => 0.0,
                'setup_addon' => 0.0,
            ];
        }

        return $tiers;
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
