<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\WordPressAdminLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWordPressAdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_service_shows_wp_admin_button(): void
    {
        [$customer, $service] = $this->makeWordPressService();

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('WP Admin')
            ->assertSee(route('customer.services.wordpress-admin', $service), false);
    }

    public function test_non_wordpress_service_hides_wp_admin_button(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting', 'name' => 'Shared']);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'name' => 'Shared Box',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Rename')
            ->assertDontSee('WP Admin');
    }

    public function test_customer_can_redirect_to_wordpress_admin_sso_url(): void
    {
        [$customer, $service] = $this->makeWordPressService();

        $this->mock(WordPressAdminLoginService::class, function ($mock) {
            $mock->shouldReceive('createLoginUrl')
                ->once()
                ->andReturn('https://sisallove.com/wp-login.php?talksasa_admin_sso=abc123');
        });

        $this->actingAs($customer)
            ->get(route('customer.services.wordpress-admin', $service))
            ->assertRedirect('https://sisallove.com/wp-login.php?talksasa_admin_sso=abc123');
    }

    public function test_customer_cannot_open_another_users_wordpress_admin(): void
    {
        [, $service] = $this->makeWordPressService();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->get(route('customer.services.wordpress-admin', $service))
            ->assertForbidden();
    }

    public function test_wordpress_admin_login_failure_returns_to_services_with_error(): void
    {
        [$customer, $service] = $this->makeWordPressService();

        $this->mock(WordPressAdminLoginService::class, function ($mock) {
            $mock->shouldReceive('createLoginUrl')
                ->once()
                ->andThrow(new \RuntimeException('WordPress container must be running to open the admin dashboard.'));
        });

        $this->actingAs($customer)
            ->from(route('customer.services.index'))
            ->get(route('customer.services.wordpress-admin', $service))
            ->assertRedirect(route('customer.services.index'))
            ->assertSessionHasErrors('error');
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeWordPressService(): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'wordpress',
            'name' => 'WordPress',
        ]);
        $product = Product::factory()->containerHosting()->create([
            'name' => 'App Hosting WordPress',
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'name' => 'WP Site',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-wordpress',
        ]);

        return [$customer, $service];
    }
}
