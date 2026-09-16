<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\WordPressAdminAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWordPressAdminAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_shows_the_wordpress_admin_card_with_the_stored_account(): void
    {
        [$customer, $service] = $this->wordPressSite();
        $service->update(['credentials' => json_encode(['admin_username' => 'siteowner', 'admin_email' => 'owner@example.test'])]);

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->assertSee('WordPress admin')
            ->assertSee('siteowner')
            ->assertSee('Reset the admin password')
            ->assertSee(route('customer.services.container.wordpress-admin.password', $service), false);
    }

    public function test_the_owner_resets_the_password_and_sees_it_once(): void
    {
        [$customer, $service] = $this->wordPressSite();
        $this->mock(WordPressAdminAccountService::class, function ($mock) use ($service) {
            $mock->shouldReceive('resetPassword')->once()
                ->withArgs(fn (Service $s, User $actor, ?string $password) => $s->id === $service->id && $password === null)
                ->andReturn(['user_id' => 7, 'username' => 'siteowner', 'email' => 'o@example.test', 'password' => 'GeneratedPass123456', 'generated' => true]);
            $mock->shouldReceive('panelState')->andReturn(null);
        });

        $this->actingAs($customer)
            ->post(route('customer.services.container.wordpress-admin.password', $service), ['mode' => 'generate'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('wordpress_admin_password', 'GeneratedPass123456')
            ->assertSessionHas('wordpress_admin_username', 'siteowner');
    }

    public function test_a_chosen_password_must_be_long_enough_and_confirmed(): void
    {
        [$customer, $service] = $this->wordPressSite();
        $this->mock(WordPressAdminAccountService::class, function ($mock) {
            $mock->shouldNotReceive('resetPassword');
            $mock->shouldReceive('panelState')->andReturn(null);
        });

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $service))
            ->post(route('customer.services.container.wordpress-admin.password', $service), ['mode' => 'custom', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $service))
            ->post(route('customer.services.container.wordpress-admin.password', $service), ['mode' => 'custom', 'password' => 'LongEnoughPassword1', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
    }

    public function test_another_customer_is_forbidden_and_a_non_wordpress_site_has_no_admin_to_manage(): void
    {
        [, $service] = $this->wordPressSite();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->post(route('customer.services.container.wordpress-admin.password', $service), ['mode' => 'generate'])
            ->assertForbidden();

        $laravel = ContainerTemplate::query()->where('slug', 'laravel')->first() ?? ContainerTemplate::factory()->create(['slug' => 'laravel']);
        $owner = User::factory()->customer()->create();
        $app = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => Product::factory()->containerHosting()->create(['container_template_id' => $laravel->id])->id,
            'status' => 'active',
        ]);
        $this->actingAs($owner)
            ->post(route('customer.services.container.wordpress-admin.password', $app), ['mode' => 'generate'])
            ->assertForbidden();
    }

    public function test_a_reseller_resets_their_own_customers_admin_and_not_a_strangers(): void
    {
        $reseller = $this->reseller();
        [$customer, $service] = $this->wordPressSite($reseller);
        $this->mock(WordPressAdminAccountService::class, function ($mock) use ($reseller) {
            $mock->shouldReceive('resetPassword')->once()
                ->withArgs(fn (Service $s, User $actor) => $actor->id === $reseller->id)
                ->andReturn(['user_id' => 7, 'username' => 'siteowner', 'email' => 'o@example.test', 'password' => 'GeneratedPass123456', 'generated' => true]);
            $mock->shouldReceive('updateEmail')->once()
                ->andReturn(['user_id' => 7, 'username' => 'siteowner', 'email' => 'fresh@example.test']);
            $mock->shouldReceive('panelState')->andReturn(null);
        });

        $this->actingAs($reseller)
            ->post(route('reseller.services.wordpress-admin.password', $service), ['mode' => 'generate'])
            ->assertRedirect(route('reseller.services.show', $service))
            ->assertSessionHas('wordpress_admin_password', 'GeneratedPass123456');

        $this->actingAs($reseller)
            ->post(route('reseller.services.wordpress-admin.email', $service), ['email' => 'fresh@example.test'])
            ->assertRedirect(route('reseller.services.show', $service))
            ->assertSessionHas('success');

        $stranger = $this->reseller();
        $this->actingAs($stranger)
            ->post(route('reseller.services.wordpress-admin.password', $service), ['mode' => 'generate'])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function wordPressSite(?User $reseller = null): array
    {
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller?->id]);
        $template = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress']);
        $product = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $node = Node::factory()->create(['type' => 'container_host', 'ip_address' => '10.0.0.9']);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller?->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'name' => 'example.test',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-wordpress',
        ]);

        return [$customer, $service];
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
            'disk_pool_gb' => 100,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }
}
