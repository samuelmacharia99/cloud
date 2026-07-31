<?php

namespace Tests\Unit\Billing;

use App\Enums\BillingMode;
use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\UsageBillingProfileService;
use App\Services\ContainerOverageBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageBillingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function profile_exposes_commercial_defaults(): void
    {
        $profile = app(UsageBillingProfileService::class);

        $this->assertTrue($profile->isEnabled());
        $this->assertGreaterThan(0, $profile->floorPriceMonthly());
        $this->assertArrayHasKey('cpu', $profile->includedLimits());
        $this->assertArrayHasKey('mailboxes', $profile->includedLimits());
        $this->assertArrayHasKey('cpu_per_core_hour', $profile->usageRates());
    }

    #[Test]
    public function usage_mode_service_gets_overage_without_product_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'monthly_price' => 1500,
            'overage_enabled' => false,
            'resource_limits' => ['cpu' => 1, 'memory' => 1024, 'disk' => 20],
            'cpu_overage_rate' => 0,
            'ram_overage_rate' => 0,
            'disk_overage_rate' => 0,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'billing_mode' => BillingMode::Usage,
            'custom_price' => 1500,
            'included_limits' => [
                'cpu' => 1,
                'memory_mb' => 512,
                'disk_gb' => 10,
                'mailboxes' => 5,
            ],
            'usage_rates' => [
                'cpu_per_core_hour' => 10,
                'ram_per_gb_hour' => 5,
                'disk_per_gb_hour' => 3,
                'mailbox_per_month' => 100,
                'bandwidth_per_gb' => 0,
            ],
            'next_due_date' => Carbon::parse('2026-03-20'),
            'commenced_at' => Carbon::parse('2026-02-20'),
        ]);

        $node = Node::factory()->create();
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'cpu_limit' => 2,
            'memory_limit_mb' => 2048,
        ]);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 80,
            'memory_used_mb' => 1536,
            'memory_limit_mb' => 2048,
            'memory_percentage' => 75,
            'disk_used_gb' => 15,
            'recorded_at' => Carbon::parse('2026-03-10'),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1500,
            'tax' => 0,
            'total' => 1500,
        ]);

        $billing = app(ContainerOverageBillingService::class);
        $this->assertTrue($billing->shouldBillOverage($service->fresh(['product', 'containerDeployment'])));
        $billing->addOverageItemsToInvoice($invoice, $service->fresh(['product', 'containerDeployment']));

        $this->assertTrue($invoice->fresh()->items()->where('description', 'like', '%Overage%')->exists());
    }

    #[Test]
    public function package_mode_without_overage_flag_skips_billing(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create([
            'overage_enabled' => false,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'billing_mode' => BillingMode::Package,
            'next_due_date' => now()->addDays(5),
        ]);

        $this->assertFalse(
            app(ContainerOverageBillingService::class)->shouldBillOverage($service->fresh(['product']))
        );
    }
}
