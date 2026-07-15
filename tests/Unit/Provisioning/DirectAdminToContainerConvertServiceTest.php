<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
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

    public function test_can_revert_and_restore_previous_directadmin_product(): void
    {
        $daProduct = Product::query()->create([
            'name' => 'Silver DA',
            'slug' => 'silver-da-'.uniqid(),
            'type' => 'shared_hosting',
            'price' => 1000,
            'monthly_price' => 1000,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $wpTemplate = ContainerTemplate::query()->create([
            'name' => 'WordPress',
            'slug' => 'wordpress-revert-'.uniqid(),
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);

        $containerProduct = Product::query()->create([
            'name' => 'WP App',
            'slug' => 'wp-app-'.uniqid(),
            'type' => 'container_hosting',
            'price' => 2000,
            'monthly_price' => 2000,
            'is_active' => true,
            'container_template_id' => $wpTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $containerProduct->id,
            'name' => 'converted-service',
            'status' => 'active',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'node_id' => null,
            'service_meta' => [
                'username' => 'sisallov',
                'domain' => 'sisallove.com',
                'da_convert' => [
                    'status' => 'failed',
                    'previous' => [
                        'product_id' => $daProduct->id,
                        'node_id' => null,
                        'provisioning_driver_key' => 'directadmin',
                        'custom_price' => null,
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $this->assertTrue($convert->canRevertToDirectAdmin($service));

        $reverted = $convert->revertToDirectAdmin($service);

        $this->assertTrue($reverted->isSharedHosting());
        $this->assertSame($daProduct->id, $reverted->product_id);
        $this->assertNull($reverted->node_id);
        $this->assertSame('directadmin', $reverted->provisioning_driver_key);
        $this->assertSame('reverted', $reverted->service_meta['da_convert']['status']);
        $this->assertFalse($convert->canRevertToDirectAdmin($reverted->fresh()));
    }

    public function test_can_force_revert_stuck_running_convert(): void
    {
        $daProduct = Product::query()->create([
            'name' => 'DA Silver',
            'slug' => 'da-silver-stuck',
            'type' => 'shared_hosting',
            'price' => 1000,
            'monthly_price' => 1000,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);
        $containerProduct = Product::query()->create([
            'name' => 'WP App',
            'slug' => 'wp-app-stuck',
            'type' => 'container_hosting',
            'price' => 2000,
            'monthly_price' => 2000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $containerProduct->id,
            'name' => 'stuck-convert',
            'status' => 'provisioning',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'da_convert' => [
                    'status' => 'running',
                    'started_at' => now()->subMinutes(40)->toIso8601String(),
                    'heartbeat_at' => now()->subMinutes(40)->toIso8601String(),
                    'previous' => [
                        'product_id' => $daProduct->id,
                        'node_id' => null,
                        'provisioning_driver_key' => 'directadmin',
                        'custom_price' => null,
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $this->assertTrue($convert->convertLooksStuck($service->service_meta['da_convert']));
        $this->assertTrue($convert->canRevertToDirectAdmin($service));
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

    public function test_available_products_for_laravel_and_static_stacks(): void
    {
        $laravelTemplate = ContainerTemplate::query()->create([
            'name' => 'Laravel Application',
            'slug' => 'laravel-'.uniqid(),
            'docker_image' => 'talksasa/laravel-runtime:8.3',
            'is_active' => true,
        ]);
        $staticTemplate = ContainerTemplate::query()->create([
            'name' => 'Static Website',
            'slug' => 'static-site-'.uniqid(),
            'docker_image' => 'nginx:alpine',
            'is_active' => true,
        ]);

        $laravelProduct = Product::query()->create([
            'name' => 'Laravel App Hosting',
            'slug' => 'laravel-app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'price' => 2500,
            'monthly_price' => 2500,
            'is_active' => true,
            'container_template_id' => $laravelTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);
        $staticProduct = Product::query()->create([
            'name' => 'Static App Hosting',
            'slug' => 'static-app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'price' => 800,
            'monthly_price' => 800,
            'is_active' => true,
            'container_template_id' => $staticTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);

        $laravelPick = $convert->availableProductsForStack('laravel');
        $this->assertFalse($laravelPick['fallback']);
        $this->assertTrue($laravelPick['products']->contains('id', $laravelProduct->id));
        $this->assertTrue($convert->productMatchesStack($laravelProduct->fresh('containerTemplate'), 'laravel'));

        $staticPick = $convert->availableProductsForStack('static_or_php');
        $this->assertTrue($staticPick['products']->contains('id', $staticProduct->id));
        $this->assertTrue($convert->productMatchesStack($staticProduct->fresh('containerTemplate'), 'static_or_php'));
    }
}
