<?php

namespace Tests\Unit\Provisioning;

use App\Jobs\ConvertDirectAdminProjectSiteJob;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\DaConvertRetryService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DaConvertRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_retry_restores_the_directadmin_row_and_requeues_with_the_saved_options(): void
    {
        Bus::fake();
        [$service, $daProduct, $daNode, $containerProduct, $emailProduct] = $this->failedPrimary();

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertTrue($result['ok'], $result['message']);
        $service->refresh();
        $this->assertSame($daProduct->id, $service->product_id);
        $this->assertSame($daNode->id, $service->node_id);
        $this->assertSame('directadmin', $service->provisioning_driver_key);
        $this->assertSame('active', $service->status->value);
        $this->assertSame('queued', $service->service_meta['da_convert']['status']);
        $this->assertSame('export blew up', $service->service_meta['da_convert']['last_error']);
        $this->assertNull($service->service_meta['da_convert']['error']);
        // The DA snapshot survives so a second failure can roll back again.
        $this->assertSame($daProduct->id, $service->service_meta['da_convert']['previous']['product_id']);

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, function (ConvertDirectAdminServiceToContainerJob $job) use ($service, $containerProduct, $emailProduct) {
            return $job->serviceId === $service->id
                && $job->productId === $containerProduct->id
                && $job->emailProductId === $emailProduct->id
                && $job->databaseName === 'blinksof_main'
                && $job->acknowledgeExtraMailboxes === true
                && $job->acknowledgeAddonSites === true;
        });
        $this->assertSame(0, Service::query()->count() - 1, 'retry must not create services');
    }

    public function test_completed_primary_can_be_re_run(): void
    {
        Bus::fake();
        [$service] = $this->failedPrimary();
        $meta = $service->service_meta;
        $meta['da_convert']['status'] = 'completed';
        $service->update(['service_meta' => $meta]);

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertTrue($result['ok'], $result['message']);
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class);
        $this->assertSame('directadmin', $service->fresh()->provisioning_driver_key);
    }

    public function test_primary_retry_is_refused_while_the_convert_is_running(): void
    {
        Bus::fake();
        [$service] = $this->failedPrimary();
        $meta = $service->service_meta;
        $meta['da_convert']['status'] = 'running';
        $meta['da_convert']['heartbeat_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still running', $result['message']);
        Bus::assertNothingDispatched();
        $this->assertSame('container', $service->fresh()->provisioning_driver_key);
    }

    public function test_primary_retry_falls_back_to_legacy_meta_when_no_options_were_stored(): void
    {
        Bus::fake();
        [$service, , , $containerProduct, $emailProduct] = $this->failedPrimary();
        $emailService = Service::factory()->create(['product_id' => $emailProduct->id, 'provisioning_driver_key' => 'mailcow']);
        $meta = $service->service_meta;
        unset($meta['da_convert']['options']);
        $meta['da_convert']['target_product_id'] = $containerProduct->id;
        $meta['da_legacy'] = ['email_service_id' => $emailService->id, 'username' => 'blinksof'];
        $service->update(['service_meta' => $meta]);

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertTrue($result['ok'], $result['message']);
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn ($job) => $job->productId === $containerProduct->id
            && $job->emailProductId === $emailProduct->id
            && $job->acknowledgeExtraMailboxes === true);
    }

    public function test_sibling_retry_requeues_the_same_site_service(): void
    {
        Bus::fake();
        $product = Product::factory()->containerHosting()->create();
        $site = Service::factory()->create([
            'product_id' => $product->id,
            'status' => 'failed',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'domain' => 'app.example.com',
                'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'da_convert' => ['status' => 'failed', 'error' => 'no docroot', 'mode' => DaConvertProgress::MODE_SITE, 'attempt' => 1],
            ],
        ]);

        $result = app(DaConvertRetryService::class)->retry($site);

        $this->assertTrue($result['ok'], $result['message']);
        $site->refresh();
        $this->assertSame('pending', $site->status->value);
        $this->assertSame('queued', $site->service_meta['da_convert']['status']);
        $this->assertSame(2, $site->service_meta['da_convert']['attempt']);
        $this->assertSame('no docroot', $site->service_meta['da_convert']['last_error']);
        Bus::assertDispatched(ConvertDirectAdminProjectSiteJob::class, fn ($job) => $job->serviceId === $site->id);
        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
        $this->assertSame(1, Service::query()->count());
    }

    public function test_sibling_retry_is_refused_while_it_is_converting(): void
    {
        Bus::fake();
        $site = Service::factory()->create([
            'status' => 'provisioning',
            'service_meta' => [
                'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'da_convert' => ['status' => 'running', 'heartbeat_at' => now()->toIso8601String()],
            ],
        ]);

        $result = app(DaConvertRetryService::class)->retry($site);

        $this->assertFalse($result['ok']);
        Bus::assertNothingDispatched();
    }

    public function test_primary_retry_clears_a_node_lock_left_by_a_crashed_run(): void
    {
        Bus::fake();
        [$service, , $daNode] = $this->failedPrimary();
        $key = ConvertDirectAdminServiceToContainerJob::overlapLockKey(ConvertDirectAdminServiceToContainerJob::nodeLockKey($daNode->id));
        $this->assertTrue(Cache::lock($key, 600)->get(), 'test takes the lock like a crashed job would');
        $this->assertTrue(app(DaConvertProgress::class)->nodeLockHeld($service));

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('stale node lock from a crashed run was cleared', $result['message']);
        $this->assertFalse(app(DaConvertProgress::class)->nodeLockHeld($service));
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class);
    }

    public function test_primary_retry_keeps_a_lock_owned_by_a_live_convert_on_the_same_node(): void
    {
        Bus::fake();
        [$service, , $daNode] = $this->failedPrimary();
        Service::factory()->create([
            'node_id' => $daNode->id,
            'status' => 'provisioning',
            'service_meta' => ['da_convert' => ['status' => 'running', 'heartbeat_at' => now()->toIso8601String()], 'da_legacy' => ['da_node_id' => $daNode->id]],
        ]);
        $key = ConvertDirectAdminServiceToContainerJob::overlapLockKey(ConvertDirectAdminServiceToContainerJob::nodeLockKey($daNode->id));
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get());

        $result = app(DaConvertRetryService::class)->retry($service);

        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString('stale node lock', $result['message']);
        $this->assertTrue(app(DaConvertProgress::class)->nodeLockHeld($service), 'a live run keeps its lock');
        $lock->release();
    }

    public function test_queued_console_label_names_a_held_node_lock(): void
    {
        [$service, , $daNode] = $this->failedPrimary();
        $meta = $service->service_meta;
        $meta['da_convert']['status'] = 'queued';
        $meta['da_convert']['queued_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);

        $view = app(DaConvertProgress::class)->operatorConvertView($service->fresh());
        $this->assertStringContainsString('Waiting for a worker', $view['convert_label']);
        $this->assertFalse($view['can_retry_convert'], 'a freshly queued convert with a free lock is left to the worker');

        $key = ConvertDirectAdminServiceToContainerJob::overlapLockKey(ConvertDirectAdminServiceToContainerJob::nodeLockKey($daNode->id));
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get());
        $view = app(DaConvertProgress::class)->operatorConvertView($service->fresh());
        $this->assertStringContainsString('still holds the node lock', $view['convert_label']);
        $this->assertTrue($view['can_retry_convert'], 'a queued convert blocked by the lock can be retried at once');
        $lock->release();

        $meta['da_convert']['queued_at'] = now()->subMinutes(11)->toIso8601String();
        $service->update(['service_meta' => $meta]);
        $this->assertTrue(app(DaConvertProgress::class)->operatorConvertView($service->fresh())['can_retry_convert'], 'a convert queued for over ten minutes can be retried');
    }

    /**
     * @return array{0: Service, 1: Product, 2: Node, 3: Product, 4: Product}
     */
    private function failedPrimary(): array
    {
        $daNode = Node::factory()->directAdmin()->create();
        $daProduct = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);
        $containerProduct = Product::factory()->containerHosting()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $containerProduct->id,
            'node_id' => null,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'service_meta' => [
                'domain' => 'example.com',
                'da_convert' => [
                    'status' => 'failed',
                    'error' => 'export blew up',
                    'mode' => DaConvertProgress::MODE_PRIMARY,
                    'previous' => [
                        'product_id' => $daProduct->id,
                        'node_id' => $daNode->id,
                        'provisioning_driver_key' => 'directadmin',
                        'custom_price' => null,
                        'status' => 'active',
                    ],
                    'options' => [
                        'product_id' => $containerProduct->id,
                        'email_product_id' => $emailProduct->id,
                        'database_name' => 'blinksof_main',
                        'acknowledge_mail_pull' => true,
                        'acknowledge_addon_sites' => true,
                    ],
                ],
            ],
        ]);

        return [$service, $daProduct, $daNode, $containerProduct, $emailProduct];
    }
}
