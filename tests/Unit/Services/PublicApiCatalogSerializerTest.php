<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\PublicApiCatalogSerializer;
use Tests\TestCase;

class PublicApiCatalogSerializerTest extends TestCase
{
    private function vpsProduct(): Product
    {
        $product = new Product([
            'name' => 'Cloud VPS',
            'description' => 'Managed VPS',
            'type' => 'vps',
            'category' => 'servers',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'setup_fee' => 50,
            'features' => ['NVMe'],
            'resource_limits' => [
                'cpu_cores' => 2,
                'ram_gb' => 4,
                'storage_gb' => 80,
                'additional_ip_monthly' => 200,
                'additional_ip_setup' => 50,
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'United States',
                        'city' => 'New York',
                        'monthly_surcharge' => 300,
                        'yearly_surcharge' => 3600,
                        'setup_surcharge' => 0,
                    ],
                    [
                        'key' => 'eu',
                        'name' => 'Europe',
                        'city' => 'Frankfurt',
                        'monthly_surcharge' => 500,
                        'yearly_surcharge' => 6000,
                        'setup_surcharge' => 0,
                    ],
                ],
            ],
        ]);
        $product->id = 10;

        return $product;
    }

    public function test_platform_product_includes_server_configuration(): void
    {
        $payload = app(PublicApiCatalogSerializer::class)->formatPlatformProduct($this->vpsProduct());

        $this->assertSame('vps', $payload['type']);
        $this->assertArrayHasKey('configuration', $payload);
        $this->assertSame(2, $payload['configuration']['specs']['cpu_cores']);
        $this->assertCount(2, $payload['configuration']['locations']);
        $this->assertSame(1300.0, $payload['configuration']['locations'][0]['prices']['monthly']);
        $this->assertSame(1500.0, $payload['configuration']['locations'][1]['prices']['monthly']);
        $this->assertNotEmpty($payload['configuration']['operating_systems']);
    }

    public function test_server_cart_fields_require_valid_configuration(): void
    {
        $serializer = app(PublicApiCatalogSerializer::class);
        $product = $this->vpsProduct();

        $this->assertSame([], $serializer->serverCartFields(new Product(['type' => 'shared_hosting']), []));

        $valid = $serializer->serverCartFields($product, [
            'location_key' => 'eu',
            'ip_count' => 2,
            'operating_system' => 'ubuntu-24.04',
            'billing_cycle' => 'monthly',
        ]);

        $this->assertNotNull($valid);
        $this->assertSame('eu', $valid['location_key']);
        $this->assertSame(2, $valid['ip_count']);
        $this->assertSame('ubuntu-24.04', $valid['operating_system']);

        $this->assertNull($serializer->serverCartFields($product, [
            'location_key' => 'invalid',
            'ip_count' => 1,
            'operating_system' => 'ubuntu-24.04',
            'billing_cycle' => 'monthly',
        ]));

        $this->assertNull($serializer->serverCartFields($product, [
            'location_key' => 'usa',
            'ip_count' => 1,
            'operating_system' => 'invalid-os',
            'billing_cycle' => 'monthly',
        ]));
    }

    public function test_reseller_product_uses_listing_prices_for_location_configuration(): void
    {
        $adminProduct = $this->vpsProduct();
        $listing = new ResellerProduct([
            'name' => 'Reseller VPS',
            'description' => 'VPS plan',
            'type' => 'vps',
            'monthly_price' => 1500,
            'yearly_price' => 18000,
            'setup_fee' => 100,
            'features' => [],
        ]);
        $listing->id = 55;
        $listing->setRelation('adminProduct', $adminProduct);

        $payload = app(PublicApiCatalogSerializer::class)->formatResellerProduct($listing);

        $this->assertSame(55, $payload['id']);
        $this->assertSame(10, $payload['admin_product_id']);
        $this->assertSame(1800.0, $payload['configuration']['locations'][0]['prices']['monthly']);
    }
}
