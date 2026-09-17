<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\DaConvertRepullService;
use App\Services\Provisioning\DaConvertRetryService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DaConvertRepullServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_a_finished_convert_with_a_reachable_directadmin_account_can_be_pulled_again(): void
    {
        [$owner, $daProduct, $service] = $this->convertedSite();
        $repull = app(DaConvertRepullService::class);

        $this->assertTrue($repull->assess($service->fresh())['ok']);
        $this->assertSame(1, $repull->assess($service->fresh())['siblings']);

        $stillOnDa = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $daProduct->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'service_meta' => ['username' => 'fresh', 'domain' => 'fresh.co.ke'],
        ]);
        $notYet = $repull->assess($stillOnDa);
        $this->assertFalse($notYet['ok']);
        $this->assertStringContainsString('has not been converted yet', implode(' ', $notYet['blockers']));

        $meta = $service->fresh()->service_meta;
        $meta['da_convert']['status'] = 'running';
        $meta['da_convert']['heartbeat_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);
        $busy = $repull->assess($service->fresh());
        $this->assertStringContainsString('still running', implode(' ', $busy['blockers']));

        $meta['da_convert']['status'] = 'completed';
        $meta['da_legacy']['home_missing_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);
        $gone = $repull->assess($service->fresh());
        $this->assertStringContainsString('found removed', implode(' ', $gone['blockers']));
    }

    #[Test]
    public function a_wipe_removes_the_siblings_and_the_container_puts_the_row_back_on_directadmin_and_keeps_the_record(): void
    {
        [$owner, $daProduct, $service, $sibling] = $this->convertedSite();

        $this->mock(DirectAdminToContainerMigrationService::class, function ($mock) {
            $mock->shouldReceive('canRepullDirectAdminFiles')->andReturn(true);
            $mock->shouldReceive('directAdminHomeKnownMissing')->andReturn(false);
            $mock->shouldReceive('directAdminHomePresent')->once()->andReturn(true);
        });
        $repull = $this->repullWithFakeTeardown();
        $repull->shouldReceive('tearDownContainer')->once();
        $repull->shouldReceive('removeSibling')->once()->andReturnUsing(fn (Service $s) => $s->delete());

        $result = $repull->wipeForRepull($service->fresh(['product', 'containerDeployment']), $owner);

        $this->assertSame(1, $result['removed_siblings']);
        $this->assertSame('user-9-service-77-wordpress', $result['container']);
        $this->assertNull(Service::query()->find($sibling->id), 'the sibling row is gone');

        $service->refresh();
        $this->assertSame($daProduct->id, (int) $service->product_id, 'the row is back on its DirectAdmin product');
        $this->assertSame('directadmin', $service->provisioning_driver_key);
        $this->assertArrayNotHasKey('status', $service->service_meta['da_convert'], 'no convert status, so the board can queue it');
        $this->assertSame($daProduct->id, (int) $service->service_meta['da_convert']['previous']['product_id'], 'the previous-product record survives for the next attempt');
        $this->assertNotEmpty($service->service_meta['da_convert']['repulled_at']);
        $this->assertSame('user-9-service-77-wordpress', $service->service_meta['da_convert']['repulls'][0]['container']);
        $this->assertArrayNotHasKey('integrity_scan', $service->service_meta, 'facts about the old container are dropped');
        $this->assertSame('whsafaris', $service->service_meta['da_legacy']['username'], 'the DirectAdmin record is kept');
    }

    #[Test]
    public function nothing_is_removed_when_the_directadmin_account_turns_out_to_be_gone(): void
    {
        [$owner, , $service, $sibling] = $this->convertedSite();
        $this->mock(DirectAdminToContainerMigrationService::class, function ($mock) {
            $mock->shouldReceive('canRepullDirectAdminFiles')->andReturn(true);
            $mock->shouldReceive('directAdminHomeKnownMissing')->andReturn(false);
            $mock->shouldReceive('directAdminHomePresent')->once()->andReturn(false);
        });
        $repull = $this->repullWithFakeTeardown();
        $repull->shouldReceive('tearDownContainer')->never();
        $repull->shouldReceive('removeSibling')->never();

        try {
            $repull->wipeForRepull($service->fresh(['product', 'containerDeployment']), $owner);
            $this->fail('expected the wipe to refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());
        }
        $this->assertNotNull(Service::query()->find($sibling->id));
        $this->assertSame('container', $service->fresh()->provisioning_driver_key);
    }

    /**
     * The real service with its real collaborators (the migrator mock bound
     * above included), only the two node-touching steps stubbed.
     */
    private function repullWithFakeTeardown(): DaConvertRepullService&MockInterface
    {
        return Mockery::mock(DaConvertRepullService::class, [
            app(DaConvertProgress::class),
            app(DirectAdminToContainerMigrationService::class),
            app(ContainerDeploymentService::class),
            app(DaConvertRetryService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
    }

    /**
     * @return array{0: User, 1: Product, 2: Service, 3: Service}
     */
    private function convertedSite(): array
    {
        $owner = User::factory()->customer()->create();
        $daProduct = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);
        $template = ContainerTemplate::query()->where('slug', 'wordpress')->first() ?? ContainerTemplate::factory()->create(['slug' => 'wordpress']);
        $engine = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $node = Node::factory()->create(['type' => 'container_host', 'ip_address' => '10.0.0.5']);
        $daNode = Node::factory()->create(['type' => 'directadmin', 'ip_address' => '10.0.0.6']);

        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $engine->id,
            'provisioning_driver_key' => 'container',
            'node_id' => $node->id,
            'status' => 'active',
            'name' => 'www.whsafaris.co.ke',
        ]);
        $project = CustomerProject::query()->create([
            'user_id' => $owner->id,
            'name' => 'whsafaris.co.ke',
            'billing_service_id' => $service->id,
            'recipe_key' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
        ]);
        $service->update([
            'project_id' => $project->id,
            'service_meta' => [
                'username' => 'whsafaris',
                'domain' => 'www.whsafaris.co.ke',
                'integrity_scan' => ['suspicious_count' => 0],
                'da_legacy' => ['username' => 'whsafaris', 'da_node_id' => $daNode->id, 'domain' => 'www.whsafaris.co.ke', 'docroot' => '/home/whsafaris/domains/www.whsafaris.co.ke/public_html', 'stack' => 'wordpress'],
                'da_convert' => [
                    'status' => 'completed',
                    'mode' => 'convert_in_place',
                    'completed_at' => now()->subDay()->toIso8601String(),
                    'attempt' => 1,
                    'target_product_id' => $engine->id,
                    'previous' => ['product_id' => $daProduct->id, 'provisioning_driver_key' => 'directadmin', 'node_id' => $daNode->id, 'status' => 'active'],
                    'options' => ['product_id' => $engine->id, 'acknowledge_mail_pull' => true, 'acknowledge_addon_sites' => true],
                ],
            ],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-9-service-77-wordpress',
        ]);

        $sibling = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $engine->id,
            'provisioning_driver_key' => 'container',
            'node_id' => $node->id,
            'project_id' => $project->id,
            'status' => 'active',
            'name' => 'blog.whsafaris.co.ke',
            'service_meta' => ['project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY, 'project_role' => 'site', 'domain' => 'blog.whsafaris.co.ke', 'da_convert' => ['status' => 'completed', 'mode' => 'project_site']],
        ]);

        return [$owner, $daProduct, $service, $sibling];
    }
}
