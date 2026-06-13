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
                'included_ips' => 1,
                'managed' => true,
                'money_back_days' => 30,
            ],
        ]);

        $lines = app(ServerProductConfigService::class)->specLines($product);

        $this->assertContains('4 CPU Cores', $lines);
        $this->assertContains('8 GB RAM', $lines);
        $this->assertContains('160 GB NVMe Storage', $lines);
        $this->assertContains('10 TB Bandwidth', $lines);
        $this->assertContains('Fully Managed Hosting', $lines);
    }

    public function test_location_pricing_with_ip_addon(): void
    {
        $product = new Product([
            'type' => 'vps',
            'monthly_price' => 1000,
            'resource_limits' => [
                'cpu_cores' => 2,
                'included_ips' => 1,
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'USA',
                        'monthly_price' => 1300,
                        'yearly_price' => 15600,
                        'setup_fee' => 100,
                        'ip_tiers' => [
                            ['ips' => 1, 'monthly_addon' => 0, 'setup_addon' => 0],
                            ['ips' => 2, 'monthly_addon' => 200, 'setup_addon' => 50],
                        ],
                    ],
                    [
                        'key' => 'eu',
                        'name' => 'Europe',
                        'monthly_price' => 1500,
                        'yearly_price' => 18000,
                        'setup_fee' => 100,
                        'ip_tiers' => [
                            ['ips' => 1, 'monthly_addon' => 0, 'setup_addon' => 0],
                        ],
                    ],
                ],
            ],
        ]);

        $service = app(ServerProductConfigService::class);

        $pricing = $service->resolveOrderPricing($product, null, 'usa', 2, 'monthly');
        $this->assertSame(1500.0, $pricing['unit_price']);
        $this->assertSame(150.0, $pricing['setup_fee']);

        $cartPricing = $service->priceForCartItem($product, [
            'location_key' => 'eu',
            'ip_count' => 1,
            'billing_cycle' => 'annual',
        ]);

        $this->assertSame(18000.0, $cartPricing['unit_price']);
        $this->assertSame(100.0, $cartPricing['setup_fee']);
    }

    public function test_normalize_from_request_syncs_product_prices(): void
    {
        $service = app(ServerProductConfigService::class);

        $config = $service->normalizeFromRequest([
            'cpu_cores' => 2,
            'ram_gb' => 4,
            'included_ips' => 1,
            'locations' => [
                [
                    'name' => 'USA',
                    'monthly_price' => 1300,
                    'yearly_price' => 15600,
                    'setup_fee' => 100,
                ],
            ],
        ], 'vps');

        $synced = $service->syncProductPricesFromConfig($config);

        $this->assertSame(1300.0, $synced['monthly_price']);
        $this->assertSame(15600.0, $synced['yearly_price']);
        $this->assertSame(100.0, $synced['setup_fee']);
    }
}
