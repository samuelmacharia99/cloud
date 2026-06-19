<?php

namespace Tests\Unit\Services;

use App\Enums\SharedHostingDomainMode;
use App\Models\DirectAdminPackage;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\Checkout\SharedHostingCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SharedHostingCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_linked_reseller_catalog_uses_reseller_directadmin_context_at_checkout(): void
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.example.com',
            'ip_address' => '10.0.0.1',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'is_active' => true,
        ]);

        $package = DirectAdminPackage::create([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'node_id' => $node->id,
            'disk_quota' => 1000,
            'bandwidth_quota' => 10000,
            'is_active' => true,
        ]);

        $adminProduct = Product::create([
            'name' => 'PHP Basic',
            'slug' => 'php-basic-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 400,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $package->id,
            'is_active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'directadmin_username' => 'reseller_acme',
            'reseller_node_id' => $node->id,
        ]);

        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'Retail PHP',
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);

        $request = Request::create('/', 'POST', [
            'hosting_domain_mode' => ['cart-1' => SharedHostingDomainMode::Existing->value],
            'hosting_domain_fqdn' => ['cart-1' => 'client.example.com'],
        ]);
        $request->setUserResolver(fn () => $customer);

        $context = app(SharedHostingCheckoutService::class)->buildSharedHostingContext(
            $request,
            'cart-1',
            $customer,
            $adminProduct,
            $invoice,
            $order,
            $listing,
        );

        $this->assertSame($node->id, $context['node_id']);
        $this->assertSame('client.example.com', $context['service_meta']['domain']);
        $this->assertSame('reseller_acme', $context['service_meta']['directadmin_reseller']);
    }

    public function test_platform_customer_without_reseller_uses_admin_directadmin_context(): void
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.example.com',
            'ip_address' => '10.0.0.2',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'is_active' => true,
        ]);

        $package = DirectAdminPackage::create([
            'name' => 'Starter',
            'package_key' => 'starter',
            'node_id' => $node->id,
            'disk_quota' => 1000,
            'bandwidth_quota' => 10000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 100,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $package->id,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);

        $request = Request::create('/', 'POST', [
            'hosting_domain_mode' => ['cart-1' => SharedHostingDomainMode::Existing->value],
            'hosting_domain_fqdn' => ['cart-1' => 'site.example.com'],
        ]);
        $request->setUserResolver(fn () => $customer);

        $context = app(SharedHostingCheckoutService::class)->buildSharedHostingContext(
            $request,
            'cart-1',
            $customer,
            $product,
            $invoice,
            $order,
        );

        $this->assertSame($node->id, $context['node_id']);
        $this->assertArrayNotHasKey('directadmin_reseller', $context['service_meta']);
    }

    public function test_from_cart_mode_links_hosting_to_domain_line_without_extra_invoice_items(): void
    {
        $node = Node::create([
            'name' => 'DA Node',
            'hostname' => 'da.example.com',
            'ip_address' => '10.0.0.3',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'is_active' => true,
        ]);

        $package = DirectAdminPackage::create([
            'name' => 'Starter',
            'package_key' => 'starter',
            'node_id' => $node->id,
            'disk_quota' => 1000,
            'bandwidth_quota' => 10000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 100,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $package->id,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);

        $cart = [
            'domain-key' => [
                'type' => 'domain',
                'domain' => 'mysite',
                'extension' => '.co.ke',
                'years' => 2,
            ],
            'hosting-key' => [
                'type' => 'shared_hosting',
                'product_id' => $product->id,
                'linked_domain_cart_key' => 'domain-key',
            ],
        ];

        $service = app(SharedHostingCheckoutService::class);

        $this->assertTrue($service->hasLinkedDomainInCart($cart, 'hosting-key'));
        $this->assertSame(0.0, $service->estimateDomainAddonTotal(Request::create('/', 'POST', [
            'hosting_domain_mode' => ['hosting-key' => SharedHostingDomainMode::FromCart->value],
        ]), $cart));

        $request = Request::create('/', 'POST', [
            'hosting_domain_mode' => ['hosting-key' => SharedHostingDomainMode::FromCart->value],
        ]);
        $request->setUserResolver(fn () => $customer);

        $context = $service->buildSharedHostingContext(
            $request,
            'hosting-key',
            $customer,
            $product,
            $invoice,
            $order,
            null,
            $cart,
            ['domain-key' => 99],
        );

        $this->assertSame('mysite.co.ke', $context['service_meta']['domain']);
        $this->assertSame(SharedHostingDomainMode::FromCart->value, $context['service_meta']['hosting_domain_mode']);
        $this->assertSame(99, $context['service_meta']['domain_id']);
        $this->assertSame('domain-key', $context['service_meta']['linked_domain_cart_key']);
        $this->assertSame([], $context['invoice_items']);
    }
}
