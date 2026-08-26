<?php

namespace Tests\Feature\Admin;

use App\Models\Currency;
use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNodeMonthlyCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_form_shows_usd_spend_and_kes_preview(): void
    {
        $this->seedUsdRate(0.0077);
        $node = Node::factory()->create(['monthly_cost_usd' => 100]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.nodes.edit', $node))
            ->assertOk()
            ->assertSee('Monthly spend')
            ->assertSee('Amount (USD)')
            ->assertSee('Kenya shillings')
            ->assertSee('100.00', false)
            ->assertSee('1 USD ≈ KES');
    }

    public function test_admin_can_save_monthly_usd_spend(): void
    {
        $this->seedUsdRate(0.0077);
        $node = Node::factory()->create(['monthly_cost_usd' => null]);

        $response = $this->actingAs($this->adminUser())
            ->put(route('admin.nodes.update', $node), $this->updatePayload($node, [
                'monthly_cost_usd' => '250.50',
            ]));

        $response->assertRedirect(route('admin.nodes.show', $node));
        $response->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertSame('250.50', $node->monthly_cost_usd);
    }

    public function test_blank_monthly_spend_clears_the_stored_amount(): void
    {
        $node = Node::factory()->create(['monthly_cost_usd' => 80]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.nodes.update', $node), $this->updatePayload($node, [
                'monthly_cost_usd' => '',
            ]))
            ->assertRedirect(route('admin.nodes.show', $node))
            ->assertSessionHasNoErrors();

        $this->assertNull($node->fresh()->monthly_cost_usd);
    }

    public function test_negative_monthly_spend_is_rejected_without_changing_the_stored_amount(): void
    {
        $node = Node::factory()->create(['monthly_cost_usd' => 80]);

        $this->actingAs($this->adminUser())
            ->from(route('admin.nodes.edit', $node))
            ->put(route('admin.nodes.update', $node), $this->updatePayload($node, [
                'monthly_cost_usd' => '-10',
            ]))
            ->assertRedirect(route('admin.nodes.edit', $node))
            ->assertSessionHasErrors('monthly_cost_usd');

        $this->assertSame('80.00', $node->fresh()->monthly_cost_usd);
    }

    public function test_omitting_monthly_spend_leaves_the_stored_amount_unchanged(): void
    {
        $node = Node::factory()->create(['monthly_cost_usd' => 80]);
        $payload = $this->updatePayload($node);
        unset($payload['monthly_cost_usd']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.nodes.update', $node), $payload)
            ->assertRedirect(route('admin.nodes.show', $node))
            ->assertSessionHasNoErrors();

        $this->assertSame('80.00', $node->fresh()->monthly_cost_usd);
    }

    public function test_show_page_displays_usd_and_converted_kes(): void
    {
        $this->seedUsdRate(0.0077);
        $node = Node::factory()->create(['monthly_cost_usd' => 100]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.nodes.show', $node))
            ->assertOk()
            ->assertSee('Monthly spend')
            ->assertSee('$100.00')
            ->assertSee('KES 12,987.01');
    }

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Node $node, array $overrides = []): array
    {
        return array_merge([
            'name' => $node->name,
            'hostname' => $node->hostname,
            'ip_address' => $node->ip_address,
            'type' => $node->type,
            'status' => $node->status,
            'cpu_cores' => $node->cpu_cores,
            'ram_gb' => $node->ram_gb,
            'storage_gb' => $node->storage_gb,
            'ssh_port' => $node->ssh_port,
            'ssh_username' => $node->ssh_username,
            'region' => $node->region,
            'verify_ssl' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    private function seedUsdRate(float $usdPerKes): void
    {
        Currency::create([
            'code' => 'KES',
            'name' => 'Kenyan Shilling',
            'symbol' => 'KSh',
            'exchange_rate' => 1.0,
            'is_active' => true,
            'order' => 1,
        ]);
        Currency::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => '$',
            'exchange_rate' => $usdPerKes,
            'is_active' => true,
            'order' => 20,
        ]);
    }
}
