<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\ResellerDomainTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterCustomerDomainTransferTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
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

    public function test_reseller_can_initiate_transfer_and_recipient_can_approve(): void
    {
        $reseller = $this->createReseller();
        $from = User::factory()->create(['reseller_id' => $reseller->id]);
        $to = User::factory()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $from->id,
            'reseller_id' => $reseller->id,
            'name' => 'transfer',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.transfer', $domain), ['to_customer_id' => $to->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame($to->id, $domain->pending_transfer_to_user_id);
        $this->assertNotNull($domain->transfer_token);

        app(ResellerDomainTransferService::class)->approve($domain->fresh()->transfer_token, $to);

        $domain->refresh();
        $this->assertSame($to->id, $domain->user_id);
        $this->assertNull($domain->transfer_token);
    }
}
