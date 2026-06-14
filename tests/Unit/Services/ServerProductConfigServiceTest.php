<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Services\ServerProductConfigService;
use Tests\TestCase;

class ServerProductConfigServiceTest extends TestCase
{
    public function test_spec_lines_from_structured_config(): void
    {
        $product = new Product([
            'type' => 'vps',
            'resource_limits' => [
                'cpu_cores' => 4,
                'ram_gb' => 8,
                'storage_gb' => 160,
                'storage_type' => 'NVMe',
                'bandwidth_tb' => 10,
                'managed' => true,
                'money_back_days' => 30,
            ],
        ]);

        $lines = app(ServerProductConfigService::class)->specLines($product);

        $this->assertContains('4 CPU Cores', $lines);
        $this->assertContains('8 GB RAM', $lines);
        $this->assertContains('160 GB NVMe Storage', $lines);
        $this->assertContains('10 TB Bandwidth', $lines);
        $this->assertContains('1 Dedicated IP included', $lines);
        $this->assertContains('Fully Managed Hosting', $lines);
    }

    public function test_additive_location_pricing_with_per_additional_ip(): void
    {
        $product = new Product([
            'type' => 'vps',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'setup_fee' => 50,
            'wholesale_monthly_price' => 800,
            'wholesale_yearly_price' => 9600,
            'resource_limits' => [
                'cpu_cores' => 2,
                'additional_ip_monthly' => 200,
                'additional_ip_setup' => 50,
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'USA',
                        'monthly_surcharge' => 300,
                        'yearly_surcharge' => 3600,
                        'setup_surcharge' => 50,
                        'wholesale_monthly_surcharge' => 200,
                        'wholesale_yearly_surcharge' => 2400,
                    ],
                    [
                        'key' => 'eu',
                        'name' => 'Europe',
                        'monthly_surcharge' => 500,
                        'yearly_surcharge' => 6000,
                        'setup_surcharge' => 0,
                    ],
                ],
            ],
        ]);

        $service = app(ServerProductConfigService::class);

        $usaResolved = $service->resolvedLocationPrices($product, $product->resource_limits['locations'][0]);
        $this->assertSame(1300.0, $usaResolved['monthly']);
        $this->assertSame(15600.0, $usaResolved['yearly']);
        $this->assertSame(100.0, $usaResolved['setup']);

        $pricing = $service->resolveOrderPricing($product, null, 'usa', 2, 'monthly');
        $this->assertSame(1500.0, $pricing['unit_price']);
        $this->assertSame(150.0, $pricing['setup_fee']);

        $threeIps = $service->resolveOrderPricing($product, null, 'usa', 3, 'monthly');
        $this->assertSame(1700.0, $threeIps['unit_price']);
        $this->assertSame(200.0, $threeIps['setup_fee']);

        $wholesale = $service->resolvedLocationPrices($product, $product->resource_limits['locations'][0], null, true);
        $this->assertSame(1000.0, $wholesale['monthly']);

        $cartPricing = $service->priceForCartItem($product, [
            'location_key' => 'eu',
            'ip_count' => 1,
            'billing_cycle' => 'annual',
        ]);

        $this->assertSame(18000.0, $cartPricing['unit_price']);
        $this->assertSame(50.0, $cartPricing['setup_fee']);
    }

    public function test_legacy_location_ip_tiers_map_to_additional_ip_price(): void
    {
        $product = new Product([
            'type' => 'vps',
            'monthly_price' => 1000,
            'resource_limits' => [
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'USA',
                        'ip_tiers' => [
                            ['ips' => 1, 'monthly_addon' => 0, 'setup_addon' => 0],
                            ['ips' => 2, 'monthly_addon' => 250, 'setup_addon' => 75],
                        ],
                    ],
                ],
            ],
        ]);

        $service = app(ServerProductConfigService::class);
        $options = $service->ipOptions($product);

        $this->assertSame(250.0, $options[1]['monthly_addon']);
        $this->assertSame(500.0, $options[2]['monthly_addon']);
        $this->assertSame(150.0, $options[2]['setup_addon']);
    }

    public function test_legacy_full_location_prices_are_treated_as_surcharges(): void
    {
        $product = new Product([
            'type' => 'vps',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'setup_fee' => 50,
            'resource_limits' => [
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'USA',
                        'monthly_price' => 1300,
                        'yearly_price' => 15600,
                        'setup_fee' => 100,
                    ],
                ],
            ],
        ]);

        $service = app(ServerProductConfigService::class);
        $resolved = $service->resolvedLocationPrices($product, $product->resource_limits['locations'][0]);

        $this->assertSame(1300.0, $resolved['monthly']);
        $this->assertSame(15600.0, $resolved['yearly']);
        $this->assertSame(100.0, $resolved['setup']);
    }
}
