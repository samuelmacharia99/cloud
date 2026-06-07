<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_from_service_activates_linked_domain(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'pending',
            'expires_at' => now()->addYear(),
        ]);

        $product = Product::factory()->create(['type' => 'domain']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'service_meta' => [
                'domain_id' => $domain->id,
                'years' => 2,
            ],
        ]);

        app(DomainActivationService::class)->activateFromService($service);

        $domain->refresh();
        $service->refresh();

        $this->assertSame('active', $domain->status);
        $this->assertNotNull($domain->registered_at);
        $this->assertTrue($domain->expires_at->isFuture());
        $this->assertSame(ServiceStatus::Active, $service->status);
    }

    public function test_activate_from_service_uses_domain_registration_years_from_hosting_checkout(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => 'mysite',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);

        $product = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'service_meta' => [
                'domain_id' => $domain->id,
                'domain_registration_years' => 3,
            ],
        ]);

        app(DomainActivationService::class)->activateFromService($service);

        $domain->refresh();

        $this->assertSame('active', $domain->status);
        $this->assertTrue($domain->expires_at->greaterThan(now()->addYears(2)));
    }

    public function test_apply_admin_activation_syncs_pending_services(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'registered_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $product = Product::factory()->create(['type' => 'domain']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'name' => 'shop.com',
            'status' => ServiceStatus::Pending,
            'service_meta' => ['domain_id' => $domain->id],
        ]);

        app(DomainActivationService::class)->applyAdminActivation($domain);

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }
}
