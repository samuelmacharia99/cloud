<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerOwnedAssetTransferTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_reseller_can_immediately_transfer_domain_between_owned_customers(): void
    {
        $reseller = $this->createReseller();
        $from = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $to = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'name' => 'transferme',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.transfer', $domain), ['to_customer_id' => $to->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertNull($domain->transfer_token);
        $this->assertNull($domain->pending_transfer_to_user_id);
    }

    public function test_reseller_can_transfer_own_wholesale_domain_to_managed_customer(): void
    {
        $reseller = $this->createReseller();
        $to = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'wholesale',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.transfer', $domain), ['to_customer_id' => $to->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertSame($reseller->id, $domain->reseller_id);
    }

    public function test_reseller_can_transfer_domain_tagged_to_reseller_when_owner_reseller_id_is_stale(): void
    {
        $reseller = $this->createReseller();
        $from = User::factory()->customer()->create(['reseller_id' => null]);
        $to = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'name' => 'staleowner',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.transfer', $domain), ['to_customer_id' => $to->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertSame($reseller->id, $domain->reseller_id);
    }

    public function test_reseller_cannot_transfer_domain_to_foreign_customer(): void
    {
        $reseller = $this->createReseller();
        $otherReseller = $this->createReseller();
        $from = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $foreign = User::factory()->customer()->create(['reseller_id' => $otherReseller->id]);

        $domain = Domain::create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'name' => 'locked',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.transfer', $domain), ['to_customer_id' => $foreign->id])
            ->assertSessionHasErrors('to_customer_id');

        $this->assertSame($from->id, $domain->fresh()->user_id);
    }

    public function test_reseller_can_transfer_service_between_owned_customers(): void
    {
        $reseller = $this->createReseller();
        $from = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $to = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $product = Product::create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1000,
            'is_active' => true,
        ]);

        $service = Service::factory()->create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => 'active',
            'name' => 'Hosting A',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.services.transfer', $service), [
                'to_customer_id' => $to->id,
            ])
            ->assertRedirect(route('reseller.services.show', $service))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame($to->id, $service->user_id);
        $this->assertSame($reseller->id, $service->reseller_id);
    }

    public function test_reseller_cannot_transfer_service_to_foreign_customer(): void
    {
        $reseller = $this->createReseller();
        $otherReseller = $this->createReseller();
        $from = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $foreign = User::factory()->customer()->create(['reseller_id' => $otherReseller->id]);

        $service = Service::factory()->create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'status' => 'active',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.services.transfer', $service), [
                'to_customer_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('to_customer_id');

        $this->assertSame($from->id, $service->fresh()->user_id);
    }
}
