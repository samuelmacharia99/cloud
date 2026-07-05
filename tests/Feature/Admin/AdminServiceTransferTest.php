<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceTransferTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    private function createReseller(string $name = 'Reseller A'): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'name' => $name,
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_admin_can_transfer_service_to_another_customer(): void
    {
        $admin = $this->createAdmin();
        $fromCustomer = User::factory()->create(['name' => 'Alice']);
        $toCustomer = User::factory()->create(['name' => 'Bob']);

        $service = Service::factory()->create([
            'user_id' => $fromCustomer->id,
            'name' => 'Alice Hosting',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.services.transfer', $service), [
                'target_user_id' => $toCustomer->id,
            ])
            ->assertRedirect(route('admin.services.show', $service))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame($toCustomer->id, $service->user_id);
        $this->assertStringContainsString('Bob', (string) $service->notes);

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'service.transfer',
            'subject_type' => Service::class,
            'subject_id' => $service->id,
        ]);
    }

    public function test_transfer_updates_reseller_id_for_target_customer(): void
    {
        $admin = $this->createAdmin();
        $resellerA = $this->createReseller('Reseller A');
        $resellerB = $this->createReseller('Reseller B');

        $fromCustomer = User::factory()->create(['reseller_id' => $resellerA->id]);
        $toCustomer = User::factory()->create(['reseller_id' => $resellerB->id]);

        $service = Service::factory()->create([
            'user_id' => $fromCustomer->id,
            'reseller_id' => $resellerA->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.services.transfer', $service), [
                'target_user_id' => $toCustomer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame($toCustomer->id, $service->user_id);
        $this->assertSame($resellerB->id, $service->reseller_id);
    }

    public function test_can_transfer_attached_domain_with_service(): void
    {
        $admin = $this->createAdmin();
        $fromCustomer = User::factory()->create();
        $toCustomer = User::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $fromCustomer->id,
            'service_meta' => ['domain' => 'example.com'],
        ]);

        $domain = Domain::create([
            'user_id' => $fromCustomer->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.services.transfer', $service), [
                'target_user_id' => $toCustomer->id,
                'transfer_domain' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($toCustomer->id, $service->fresh()->user_id);
        $this->assertSame($toCustomer->id, $domain->fresh()->user_id);
    }

    public function test_cannot_transfer_service_to_same_customer(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($admin)
            ->from(route('admin.services.show', $service))
            ->post(route('admin.services.transfer', $service), [
                'target_user_id' => $customer->id,
            ])
            ->assertRedirect(route('admin.services.show', $service))
            ->assertSessionHas('error');

        $this->assertSame($customer->id, $service->fresh()->user_id);
    }

    public function test_cannot_transfer_service_to_reseller_account(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $reseller = $this->createReseller();
        $service = Service::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($admin)
            ->post(route('admin.services.transfer', $service), [
                'target_user_id' => $reseller->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_transfer_preview_returns_json_summary(): void
    {
        $admin = $this->createAdmin();
        $fromCustomer = User::factory()->create(['name' => 'Alice']);
        $toCustomer = User::factory()->create(['name' => 'Bob']);
        $service = Service::factory()->create(['user_id' => $fromCustomer->id]);

        $this->actingAs($admin)
            ->getJson(route('admin.services.transfer-preview', $service).'?target_user_id='.$toCustomer->id)
            ->assertOk()
            ->assertJsonPath('from.name', 'Alice')
            ->assertJsonPath('to.name', 'Bob')
            ->assertJsonPath('service.id', $service->id);
    }
}
