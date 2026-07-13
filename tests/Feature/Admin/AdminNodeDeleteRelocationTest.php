<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\NginxProxyService;
use App\Services\Provisioning\NodeServiceRelocationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminNodeDeleteRelocationTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_delete_with_services_redirects_to_confirm_page(): void
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'node_id' => $node->id,
            'product_id' => $product->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
        ]);

        $response = $this->actingAs($this->adminUser())
            ->delete(route('admin.nodes.delete', $node));

        $response->assertRedirect(route('admin.nodes.delete-confirm', $node));
        $this->assertDatabaseHas('nodes', ['id' => $node->id]);
    }

    public function test_confirm_page_lists_services_and_targets(): void
    {
        $source = Node::factory()->containerHost()->create(['name' => 'Old Node']);
        $target = Node::factory()->containerHost()->create(['name' => 'New Node']);
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'node_id' => $source->id,
            'product_id' => $product->id,
            'name' => 'App Alpha',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $source->id,
            'container_name' => 'talksasa-alpha',
        ]);

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.nodes.delete-confirm', $source));

        $response->assertOk();
        $response->assertSee('App Alpha');
        $response->assertSee('New Node');
        $response->assertDontSee('Delete node</button>', false);
    }

    public function test_empty_node_can_be_deleted_from_confirm_page(): void
    {
        $node = Node::factory()->containerHost()->create(['name' => 'Empty Box']);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.nodes.delete', $node))
            ->assertRedirect(route('admin.nodes.index'));

        $this->assertDatabaseMissing('nodes', ['id' => $node->id]);
    }

    public function test_apply_relocates_found_containers_and_allows_delete(): void
    {
        $admin = $this->adminUser();
        $source = Node::factory()->containerHost()->create();
        $target = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'node_id' => $source->id,
            'product_id' => $product->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $source->id,
            'container_name' => 'talksasa-move-me',
            'assigned_port' => 30111,
            'status' => 'running',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->andReturnUsing(function (string $command) {
                if (str_contains($command, 'NetworkSettings.Ports')) {
                    return json_encode(['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '30111']]]);
                }

                return '';
            });
        $ssh->shouldReceive('disconnect')->once();

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn([
            'missing' => false,
            'running' => true,
            'state' => 'running',
            'oom_killed' => false,
            'exit_code' => 0,
        ]);

        $nginx = Mockery::mock(NginxProxyService::class);
        $nginx->shouldReceive('removeProxyConfig')->zeroOrMoreTimes();
        $nginx->shouldReceive('bind')->zeroOrMoreTimes();

        $serviceLocator = (new NodeServiceRelocationService($inspector, $nginx))
            ->usingSshFactory(fn () => $ssh);

        $this->app->instance(NodeServiceRelocationService::class, $serviceLocator);

        $this->actingAs($admin)
            ->post(route('admin.nodes.relocate-services', $source), [
                'target_node_id' => $target->id,
                'action' => 'apply',
            ])
            ->assertRedirect(route('admin.nodes.delete-confirm', $source));

        $service->refresh();
        $deployment->refresh();

        $this->assertSame($target->id, $service->node_id);
        $this->assertSame($target->id, $deployment->node_id);
        $this->assertSame($source->id, $deployment->migrated_from_node_id);
        $this->assertSame('node_delete_rescan', $deployment->migration_reason);
        $this->assertSame(30111, $deployment->assigned_port);

        $this->actingAs($admin)
            ->delete(route('admin.nodes.delete', $source))
            ->assertRedirect(route('admin.nodes.index'));

        $this->assertDatabaseMissing('nodes', ['id' => $source->id]);
    }

    public function test_scan_marks_missing_containers_without_updating(): void
    {
        $source = Node::factory()->containerHost()->create();
        $target = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'node_id' => $source->id,
            'product_id' => $product->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $source->id,
            'container_name' => 'talksasa-absent',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn('');
        $ssh->shouldReceive('disconnect')->once();

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn([
            'missing' => true,
            'running' => false,
            'state' => 'unknown',
            'oom_killed' => false,
            'exit_code' => null,
        ]);

        $relocation = (new NodeServiceRelocationService($inspector, Mockery::mock(NginxProxyService::class)))
            ->usingSshFactory(fn () => $ssh);

        $this->app->instance(NodeServiceRelocationService::class, $relocation);

        $this->actingAs($this->adminUser())
            ->post(route('admin.nodes.relocate-services', $source), [
                'target_node_id' => $target->id,
                'action' => 'scan',
            ])
            ->assertRedirect(route('admin.nodes.delete-confirm', $source));

        $this->assertSame($source->id, $service->fresh()->node_id);
    }

    public function test_directadmin_apply_relocates_found_accounts_and_resellers(): void
    {
        $admin = $this->adminUser();
        $source = Node::factory()->directAdmin()->create(['name' => 'DA Old']);
        $target = Node::factory()->directAdmin()->create(['name' => 'DA New']);

        $service = Service::factory()->create([
            'node_id' => $source->id,
            'name' => 'Shared Site',
            'external_reference' => 'custsite',
            'service_meta' => ['username' => 'custsite'],
            'provisioning_driver_key' => 'directadmin',
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_node_id' => $source->id,
            'directadmin_username' => 'res_acme',
            'email' => 'reseller@example.com',
        ]);

        $da = Mockery::mock(DirectAdminService::class);
        $da->shouldReceive('isConfigured')->andReturn(true);
        $da->shouldReceive('accountExists')->with('custsite')->andReturn(true);
        $da->shouldReceive('accountExists')->with('res_acme')->andReturn(true);
        $da->shouldReceive('getAccountLiveStatus')->with('custsite')->andReturn([
            'live_status' => 'active',
            'label' => 'Active on DirectAdmin',
            'detail' => ['username' => 'custsite'],
        ]);
        $da->shouldReceive('getAccountLiveStatus')->with('res_acme')->andReturn([
            'live_status' => 'active',
            'label' => 'Active on DirectAdmin',
            'detail' => ['username' => 'res_acme'],
        ]);

        $relocation = (new NodeServiceRelocationService)
            ->usingDirectAdminFactory(fn () => $da);

        $this->app->instance(NodeServiceRelocationService::class, $relocation);

        $this->actingAs($admin)
            ->post(route('admin.nodes.relocate-services', $source), [
                'target_node_id' => $target->id,
                'action' => 'apply',
            ])
            ->assertRedirect(route('admin.nodes.delete-confirm', $source));

        $this->assertSame($target->id, $service->fresh()->node_id);
        $this->assertSame($target->id, $reseller->fresh()->reseller_node_id);

        $this->actingAs($admin)
            ->delete(route('admin.nodes.delete', $source))
            ->assertRedirect(route('admin.nodes.index'));

        $this->assertDatabaseMissing('nodes', ['id' => $source->id]);
    }

    public function test_directadmin_scan_skips_missing_accounts(): void
    {
        $source = Node::factory()->directAdmin()->create();
        $target = Node::factory()->directAdmin()->create();

        $service = Service::factory()->create([
            'node_id' => $source->id,
            'external_reference' => 'goneuser',
            'service_meta' => ['username' => 'goneuser'],
            'provisioning_driver_key' => 'directadmin',
        ]);

        $da = Mockery::mock(DirectAdminService::class);
        $da->shouldReceive('isConfigured')->andReturn(true);
        $da->shouldReceive('accountExists')->with('goneuser')->andReturn(false);

        $this->app->instance(
            NodeServiceRelocationService::class,
            (new NodeServiceRelocationService)->usingDirectAdminFactory(fn () => $da)
        );

        $this->actingAs($this->adminUser())
            ->post(route('admin.nodes.relocate-services', $source), [
                'target_node_id' => $target->id,
                'action' => 'apply',
            ])
            ->assertRedirect(route('admin.nodes.delete-confirm', $source));

        $this->assertSame($source->id, $service->fresh()->node_id);
    }

    public function test_detach_clears_remaining_records_and_allows_delete(): void
    {
        $admin = $this->adminUser();
        $node = Node::factory()->directAdmin()->create();

        $service = Service::factory()->create([
            'node_id' => $node->id,
            'status' => 'terminated',
            'external_reference' => 'siriyetu',
            'service_meta' => ['username' => 'siriyetu'],
            'provisioning_driver_key' => 'directadmin',
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_node_id' => $node->id,
            'directadmin_username' => 'res_left',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.nodes.detach-services', $node))
            ->assertRedirect(route('admin.nodes.delete-confirm', $node));

        $this->assertNull($service->fresh()->node_id);
        $this->assertNull($reseller->fresh()->reseller_node_id);

        $this->actingAs($admin)
            ->delete(route('admin.nodes.delete', $node))
            ->assertRedirect(route('admin.nodes.index'));

        $this->assertDatabaseMissing('nodes', ['id' => $node->id]);
    }
}
