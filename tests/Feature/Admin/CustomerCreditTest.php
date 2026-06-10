<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_credit_from_customer_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($admin)->post(route('admin.customers.add-credit', $customer), [
            'amount' => 1500,
            'source' => 'admin',
            'notes' => 'Manual goodwill credit',
        ]);

        $response->assertRedirect(route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits']));
        $response->assertSessionHas('success');

        $this->assertSame(1500.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_customer_profile_shows_credits_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        CreditService::createManualCredit($customer, 250, 'Test');

        $this->actingAs($admin)
            ->get(route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits']))
            ->assertOk()
            ->assertSee('Add credit')
            ->assertSee('250.00');
    }
}
