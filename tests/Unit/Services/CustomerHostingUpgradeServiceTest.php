<?php

namespace Tests\Unit\Services;

use App\Models\DirectAdminPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\NotificationService;
use App\Services\Provisioning\DirectAdminSetupService;
use App\Services\ResellerCustomerCatalogService;
use PHPUnit\Framework\TestCase;

class CustomerHostingUpgradeServiceTest extends TestCase
{
    private function makeService(): CustomerHostingUpgradeService
    {
        return new CustomerHostingUpgradeService(
            $this->createMock(DirectAdminSetupService::class),
            new ResellerCustomerCatalogService,
            $this->createMock(NotificationService::class),
        );
    }

    private function makePackage(array $overrides = []): DirectAdminPackage
    {
        $package = new DirectAdminPackage(array_merge([
            'name' => 'Starter',
            'package_key' => 'starter',
            'disk_quota' => 10,
            'bandwidth_quota' => -1,
            'num_databases' => 5,
        ], $overrides));

        $package->id = $overrides['id'] ?? 1;

        return $package;
    }

    public function test_viable_resource_upgrade_allows_unlimited_bandwidth_on_current_plan(): void
    {
        $service = $this->makeService();
        $method = (new \ReflectionClass(CustomerHostingUpgradeService::class))
            ->getMethod('isViableResourceUpgrade');
        $method->setAccessible(true);

        $current = $this->makePackage(['disk_quota' => 10, 'bandwidth_quota' => -1, 'num_databases' => 5]);
        $candidate = $this->makePackage(['disk_quota' => 50, 'bandwidth_quota' => 100, 'num_databases' => 10]);

        $this->assertTrue($method->invoke($service, $current, $candidate));
    }

    public function test_viable_resource_upgrade_rejects_lower_disk(): void
    {
        $service = $this->makeService();
        $method = (new \ReflectionClass(CustomerHostingUpgradeService::class))
            ->getMethod('isViableResourceUpgrade');
        $method->setAccessible(true);

        $current = $this->makePackage(['disk_quota' => 50, 'bandwidth_quota' => 100, 'num_databases' => 10]);
        $candidate = $this->makePackage(['disk_quota' => 10, 'bandwidth_quota' => 100, 'num_databases' => 10]);

        $this->assertFalse($method->invoke($service, $current, $candidate));
    }

    public function test_effective_cycle_price_uses_product_price_for_platform_customers(): void
    {
        $service = $this->makeService();
        $method = (new \ReflectionClass(CustomerHostingUpgradeService::class))
            ->getMethod('effectiveCyclePrice');
        $method->setAccessible(true);

        $user = new User;
        $product = new Product(['monthly_price' => 1500, 'yearly_price' => 15000]);

        $this->assertSame(1500.0, $method->invoke($service, $user, $product, 'monthly'));
        $this->assertSame(15000.0, $method->invoke($service, $user, $product, 'annual'));
    }
}
