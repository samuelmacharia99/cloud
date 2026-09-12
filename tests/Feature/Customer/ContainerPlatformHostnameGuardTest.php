<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform hostname is the platform's, not the customer's. It cannot be
 * renamed, removed or re-issued from either console, because the stack would
 * lose the one address it is guaranteed to have.
 */
class ContainerPlatformHostnameGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_customer_cannot_remove_rename_or_reissue_the_platform_hostname(): void
    {
        [$user, $service, $domain] = $this->platformHostname();
        $this->mock(NginxProxyService::class, function ($mock): void {
            $mock->shouldNotReceive('unbind');
            $mock->shouldNotReceive('enableSsl');
            $mock->shouldNotReceive('bind');
        });

        $this->actingAs($user)
            ->delete(route('customer.services.container.domains.unbind', [$service, $domain]))
            ->assertRedirect()
            ->assertSessionHasErrors('error');
        $this->actingAs($user)
            ->patch(route('customer.services.container.domains.update', [$service, $domain]), ['domain' => 'mine.example.com'])
            ->assertRedirect()
            ->assertSessionHasErrors('error');
        $this->actingAs($user)
            ->post(route('customer.services.container.domains.ssl', [$service, $domain]))
            ->assertRedirect()
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('container_domains', ['id' => $domain->id, 'domain' => $domain->domain]);
    }

    #[Test]
    public function an_admin_cannot_remove_or_reissue_it_either(): void
    {
        [, $service, $domain] = $this->platformHostname();
        $admin = User::factory()->admin()->create();
        $this->mock(NginxProxyService::class, function ($mock): void {
            $mock->shouldNotReceive('unbind');
            $mock->shouldNotReceive('enableSsl');
        });

        $this->actingAs($admin)
            ->from(route('admin.services.show', $service))
            ->delete(route('admin.services.container.domains.unbind', [$service, $domain]))
            ->assertRedirect()
            ->assertSessionHasErrors('error');
        $this->actingAs($admin)
            ->from(route('admin.services.show', $service))
            ->post(route('admin.services.container.domains.ssl', [$service, $domain]))
            ->assertRedirect()
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('container_domains', ['id' => $domain->id]);
    }

    /**
     * @return array{0: User, 1: Service, 2: ContainerDomain}
     */
    private function platformHostname(): array
    {
        $user = User::factory()->create();
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => $deployment->container_name.'.apps.example.com',
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'active',
        ]);

        return [$user, $service->fresh(), $domain];
    }
}
