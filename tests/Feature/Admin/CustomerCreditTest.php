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
            ->assertSee('Remove credit')
            ->assertSee('250.00');
    }

    public function test_admin_can_remove_credit_from_customer_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        CreditService::createManualCredit($customer, 1500, 'Initial credit');

        $response = $this->actingAs($admin)->post(route('admin.customers.remove-credit', $customer), [
            'remove_amount' => 500,
            'remove_notes' => 'Issued in error',
        ]);

        $response->assertRedirect(route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits']));
        $response->assertSessionHas('success');

        $this->assertSame(1000.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_admin_cannot_remove_more_credit_than_available(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        CreditService::createManualCredit($customer, 200, 'Initial credit');

        $response = $this->actingAs($admin)->post(route('admin.customers.remove-credit', $customer), [
            'remove_amount' => 500,
            'remove_notes' => 'Too much removal',
        ]);

        $response->assertRedirect(route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits']));
        $response->assertSessionHasErrors('remove_amount');
        $this->assertSame(200.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_admin_can_revoke_single_credit_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $credit = CreditService::createManualCredit($customer, 800, 'Single credit');

        $response = $this->actingAs($admin)->post(route('admin.customers.revoke-credit', [$customer, $credit]), [
            'notes' => 'Revoked from customer profile',
        ]);

        $response->assertRedirect(route('admin.customers.show', ['customer' => $customer, 'tab' => 'credits']));
        $response->assertSessionHas('success');

        $this->assertSame(0.0, CreditService::getAvailableBalance($customer->fresh()));
        $this->assertSame('refunded', $credit->fresh()->status);
    }
}
