<?php

namespace Tests\Unit\Billing;

use App\Enums\BillingMode;
use App\Models\DomainDeploymentLock;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\UsageBillingProfileService;
use App\Services\Billing\UsageDeployGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageDeployGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function free_period_is_once_per_account(): void
    {
        config(['usage_billing.free_period.enabled' => true, 'usage_billing.free_period.once_per_account' => true]);

        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $guard = app(UsageDeployGuardService::class);

        $this->assertTrue($guard->qualifiesForFreePeriod($user));

        Service::factory()->create([
            'user_id' => $user->id,
            'billing_mode' => BillingMode::Usage,
            'service_meta' => ['usage_free_period_granted' => true],
        ]);

        $this->assertFalse($guard->qualifiesForFreePeriod($user->fresh()));
    }

    #[Test]
    public function checkout_hosting_price_is_zero_when_free_eligible(): void
    {
        config([
            'usage_billing.enabled' => true,
            'usage_billing.free_period.enabled' => true,
            'usage_billing.free_period.zero_checkout_hosting' => true,
            'usage_billing.floor_price_monthly' => 1500,
        ]);

        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $product = Product::factory()->containerHosting()->create(['monthly_price' => 2000]);
        $profile = app(UsageBillingProfileService::class);

        $this->assertSame(0.0, $profile->checkoutHostingPrice($user, $product));

        Service::factory()->create([
            'user_id' => $user->id,
            'billing_mode' => BillingMode::Usage,
            'service_meta' => ['usage_free_period_granted' => true],
        ]);

        $this->assertSame(2000.0, $profile->checkoutHostingPrice($user->fresh(), $product));
    }

    #[Test]
    public function locked_domain_blocks_redeploy(): void
    {
        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $other = User::factory()->customer()->create(['reseller_id' => null]);
        $guard = app(UsageDeployGuardService::class);

        DomainDeploymentLock::create([
            'fqdn' => 'locked.example',
            'user_id' => $other->id,
            'status' => DomainDeploymentLock::STATUS_LOCKED,
            'locked_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $guard->assertDomainAvailable($user, 'locked.example');
    }

    #[Test]
    public function cool_down_blocks_until_expiry(): void
    {
        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $guard = app(UsageDeployGuardService::class);

        DomainDeploymentLock::create([
            'fqdn' => 'cool.example',
            'user_id' => $user->id,
            'status' => DomainDeploymentLock::STATUS_COOLDOWN,
            'cool_down_until' => now()->addDays(10),
        ]);

        try {
            $guard->assertDomainAvailable($user, 'cool.example');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('primary_domain', $e->errors());
        }

        DomainDeploymentLock::query()->where('fqdn', 'cool.example')->update([
            'cool_down_until' => now()->subDay(),
        ]);

        $guard->assertDomainAvailable($user, 'cool.example');
        $this->assertTrue(true);
    }

    #[Test]
    public function concurrent_app_limit_blocks_second_free_deploy(): void
    {
        config([
            'usage_billing.abuse.max_concurrent_apps' => 1,
            'usage_billing.abuse.max_concurrent_apps_after_payment' => 5,
        ]);

        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $product = Product::factory()->containerHosting()->create();
        $guard = app(UsageDeployGuardService::class);

        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'billing_mode' => BillingMode::Usage,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);

        $this->expectException(ValidationException::class);
        $guard->assertWithinConcurrentLimit($user);
    }

    #[Test]
    public function finalize_locks_domain_and_sets_free_period(): void
    {
        config(['usage_billing.free_period.days' => 30]);

        $user = User::factory()->customer()->create(['reseller_id' => null]);
        $product = Product::factory()->containerHosting()->create(['monthly_price' => 1800]);
        $profile = app(UsageBillingProfileService::class);

        $service = Service::factory()->create(array_merge([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'service_meta' => [],
        ], $profile->newUsageServiceAttributes($product)));

        $profile->finalizeNewUsageContainerService($service, $user, [
            'usage_free_period' => true,
            'primary_domain' => 'App.Example.COM',
        ]);

        $service->refresh();
        $this->assertSame('app.example.com', $service->service_meta['primary_domain']);
        $this->assertTrue($service->service_meta['usage_free_period_granted']);
        $this->assertEqualsWithDelta(1800.0, (float) $service->custom_price, 0.01);
        $this->assertTrue($service->next_due_date->isAfter(now()->addDays(25)));

        $lock = DomainDeploymentLock::query()->where('fqdn', 'app.example.com')->first();
        $this->assertNotNull($lock);
        $this->assertSame(DomainDeploymentLock::STATUS_LOCKED, $lock->status);
        $this->assertSame($service->id, $lock->service_id);
    }
}
