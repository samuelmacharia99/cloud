<?php

namespace Tests\Feature\Customer;

use App\Models\Domain;
use App\Models\User;
use App\Services\Customer\CustomerNextStepsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerNextStepsDomainLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiring_domain_step_links_to_domains_index(): void
    {
        $customer = User::factory()->customer()->create();

        Domain::create([
            'user_id' => $customer->id,
            'name' => 'expiring',
            'extension' => '.com',
            'type' => 'registration',
            'status' => 'active',
            'expires_at' => now()->addDays(10),
        ]);

        $steps = app(CustomerNextStepsService::class)->forUser($customer);
        $domainStep = collect($steps)->first(fn (array $step) => str_starts_with($step['id'], 'domain-'));

        $this->assertNotNull($domainStep);
        $this->assertSame(route('customer.domains.index'), $domainStep['url']);
    }

    public function test_dashboard_loads_when_customer_has_expiring_domain(): void
    {
        $customer = User::factory()->customer()->create();

        Domain::create([
            'user_id' => $customer->id,
            'name' => 'expiring',
            'extension' => '.com',
            'type' => 'registration',
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
