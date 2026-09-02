<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\User;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRenewalRegistrarPushTest extends TestCase
{
    use RefreshDatabase;

    private function createPushedRenewal(): array
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'pushme',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addMonths(2),
        ]);

        $renewal = DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $reseller->id,
            'years' => 1,
            'amount' => 1400,
            'status' => 'pushed',
            'pushed_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        return [$admin, $renewal];
    }

    public function test_admin_can_push_renewal_to_registrar(): void
    {
        [$admin, $renewal] = $this->createPushedRenewal();

        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewalManually')
                ->once()
                ->andReturn([
                    'success' => true,
                    'completed' => true,
                    'message' => 'Domain pushme.com renewed at Cosmotown.',
                ]);
        });

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.push-registrar', $renewal))
            ->assertRedirect()
            ->assertSessionHas('success', 'Domain pushme.com renewed at Cosmotown.');
    }

    public function test_renew_via_api_does_not_complete_when_registrar_is_unavailable(): void
    {
        [$admin, $renewal] = $this->createPushedRenewal();

        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewalManually')
                ->once()
                ->andReturn([
                    'success' => false,
                    'completed' => false,
                    'message' => 'No API registrar is configured for this TLD. Renew it at the registrar, then mark as renewed.',
                ]);
        });

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.complete', $renewal))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pushed', $renewal->fresh()->status);
    }

    public function test_invoiced_renewal_cannot_be_pushed_to_registrar(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'notready',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $renewal = DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $reseller->id,
            'years' => 1,
            'amount' => 1400,
            'status' => 'invoiced',
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domain-renewals.push-registrar', $renewal))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
