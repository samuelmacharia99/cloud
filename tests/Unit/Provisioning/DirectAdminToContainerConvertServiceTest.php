<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectAdminToContainerConvertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_classify_mailboxes_treats_username_as_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
            ['account' => 'info', 'email' => 'info@example.com'],
            ['account' => 'sales@example.com', 'email' => 'sales@example.com'],
        ]);

        $this->assertTrue($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame('acmeuser@example.com', $result['default_mailboxes'][0]['email']);
        $this->assertCount(2, $result['extra_mailboxes']);
    }

    public function test_classify_mailboxes_only_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
        ]);

        $this->assertFalse($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame([], $result['extra_mailboxes']);
    }

    public function test_available_wordpress_products_lists_templated_products(): void
    {
        $template = ContainerTemplate::query()->create([
            'name' => 'WordPress Application',
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'WP Starter',
            'slug' => 'wp-starter',
            'type' => 'container_hosting',
            'price' => 1500,
            'monthly_price' => 1500,
            'is_active' => true,
            'container_template_id' => $template->id,
            'provisioning_driver_key' => 'container',
        ]);

        Product::query()->create([
            'name' => 'Laravel Pro',
            'slug' => 'laravel-pro',
            'type' => 'container_hosting',
            'price' => 2000,
            'monthly_price' => 2000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $service = app(DirectAdminToContainerConvertService::class);
        $pick = $service->availableWordPressProducts();

        $this->assertFalse($pick['fallback']);
        $this->assertTrue($pick['products']->contains('id', $product->id));
        $this->assertTrue($service->productIsWordPressContainer($product->fresh('containerTemplate')));
    }
}
