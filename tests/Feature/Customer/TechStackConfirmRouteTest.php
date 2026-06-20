<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechStackConfirmRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_confirm_techstack_without_session_redirects_to_selection(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertRedirect(route('customer.select-techstack'));
    }

    public function test_get_confirm_techstack_works_after_post_redirect(): void
    {
        $customer = User::factory()->customer()->create();

        session(['selected_techstack' => [
            'language_id' => 999,
            'language_name' => 'Missing',
            'hosting_type' => 'container',
        ]]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertRedirect(route('customer.select-techstack'));
    }
}
