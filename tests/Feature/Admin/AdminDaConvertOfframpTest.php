<?php

namespace Tests\Feature\Admin;

use App\Enums\DaConvertBatchItemStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\DaAccountSnapshot;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AdminDaConvertOfframpTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_reseller_offramp_and_see_da_services(): void
    {
        [$admin, $reseller, $ready] = $this->board();

        $this->actingAs($admin)
            ->get(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertOk()
            ->assertSee('DirectAdmin off-ramp')
            ->assertSee($ready->name)
            ->assertSee('Queue selected converts');
    }

    public function test_admin_queues_ready_accounts_and_skips_blockers(): void
    {
        Bus::fake();
        [$admin, $reseller, $ready, $blocked, $container] = $this->board();
        $this->bindConvertMock($container, [
            $ready->id => $this->preflightOk(),
            $blocked->id => $this->preflightBlocked('No Mailcow node is available.'),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$ready->id, $blocked->id],
                'product_id' => $container->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHas('success');

        $batch = DaConvertBatch::query()->first();
        $this->assertNotNull($batch);
        $this->assertSame(DaConvertBatchItemStatus::Queued, $batch->items->firstWhere('service_id', $ready->id)?->status);
        $this->assertSame(DaConvertBatchItemStatus::Blocked, $batch->items->firstWhere('service_id', $blocked->id)?->status);

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, function (ConvertDirectAdminServiceToContainerJob $job) use ($ready, $container): bool {
            return $job->serviceId === $ready->id
                && $job->productId === $container->id
                && $job->queue === DaConvertOfframpService::QUEUE
                && $job->batchItemId !== null;
        });
        Bus::assertDispatchedTimes(ConvertDirectAdminServiceToContainerJob::class, 1);
    }

    public function test_capacity_reject_does_not_create_a_batch_or_dispatch_jobs(): void
    {
        Bus::fake();
        [$admin, $reseller, $ready, , $container] = $this->board();
        $convert = $this->bindConvertMock($container, [
            $ready->id => $this->preflightOk(),
        ]);
        $convert->shouldReceive('assertHostCapacityForConvert')
            ->once()
            ->andThrow(new \DomainException('No container host has capacity for this batch.'));

        $this->actingAs($admin)
            ->from(route('admin.resellers.directadmin-offramp', $reseller))
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$ready->id],
                'product_id' => $container->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHasErrors('error');

        $this->assertSame(0, DaConvertBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_dns_snapshot_failure_blocks_the_account_and_dispatches_nothing(): void
    {
        Bus::fake();
        [$admin, $reseller, $ready, , $container] = $this->board();
        $this->bindConvertMock($container, [
            $ready->id => $this->preflightOk(),
        ]);
        $snapshots = Mockery::mock(DaAccountSnapshotService::class);
        $snapshots->shouldReceive('captureOrFail')->once()->andThrow(new \RuntimeException('Could not capture DNS for shop.example.com.'));
        $this->app->instance(DaAccountSnapshotService::class, $snapshots);

        $this->actingAs($admin)
            ->from(route('admin.resellers.directadmin-offramp', $reseller))
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$ready->id],
                'product_id' => $container->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHas('success');

        $batch = DaConvertBatch::query()->first();
        $this->assertNotNull($batch);
        $this->assertSame(DaConvertBatchItemStatus::Blocked, $batch->items->first()?->status);
        $this->assertStringContainsString('Could not capture DNS', (string) $batch->items->first()?->error);
        Bus::assertNothingDispatched();
    }

    public function test_another_resellers_service_is_not_queued(): void
    {
        Bus::fake();
        [$admin, $reseller, $ready, , $container] = $this->board();
        $other = $this->createReseller();
        $foreign = $this->daService($other, 'foreign.example.com');
        $this->bindConvertMock($container, [
            $ready->id => $this->preflightOk(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.resellers.directadmin-offramp', $reseller))
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$foreign->id],
                'product_id' => $container->id,
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHasErrors('error');

        $this->assertSame(0, DaConvertBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_non_admin_cannot_open_or_queue_offramp(): void
    {
        [$admin, $reseller, $ready, , $container] = $this->board();
        unset($admin);
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$ready->id],
                'product_id' => $container->id,
                'confirm_silent' => '1',
            ])
            ->assertForbidden();
    }

    public function test_cut_web_dns_rejects_items_from_another_reseller_batch(): void
    {
        [$admin, $reseller] = $this->board();
        $other = $this->createReseller();
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $other->id,
            'admin_user_id' => $admin->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => 'queued',
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $this->daService($other, 'other.example.com')->id,
            'status' => 'waiting_dns',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.resellers.directadmin-offramp.cut-dns', [$reseller, $batch]), [
                'item_ids' => [$item->id],
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: User, 2: Service, 3: Service, 4: Product}
     */
    private function board(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = $this->createReseller();
        $ready = $this->daService($reseller, 'ready.example.com');
        $blocked = $this->daService($reseller, 'blocked.example.com');
        $container = Product::factory()->containerHosting()->create(['name' => 'App Hosting Medium']);

        return [$admin, $reseller, $ready, $blocked, $container];
    }

    private function createReseller(): User
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

    private function daService(User $reseller, string $name): Service
    {
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        return Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'name' => $name,
            'external_reference' => 'da-'.substr(md5($name), 0, 8),
            'service_meta' => ['username' => 'da-'.substr(md5($name), 0, 8), 'domain' => $name],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $preflights
     */
    private function bindConvertMock(Product $container, array $preflights): DirectAdminToContainerConvertService
    {
        $convert = Mockery::mock(DirectAdminToContainerConvertService::class);
        $convert->shouldReceive('applicationHostingCatalog')->andReturn([
            'products' => collect([$container]),
            'recommended' => collect(),
            'fallback' => true,
        ]);
        $convert->shouldReceive('preflight')->andReturnUsing(function (Service $service) use ($preflights): array {
            return $preflights[$service->id] ?? $this->preflightBlocked('Unexpected service.');
        });
        $convert->shouldReceive('assertHostCapacityForConvert')->andReturnNull()->byDefault();
        $this->app->instance(DirectAdminToContainerConvertService::class, $convert);

        $snapshots = Mockery::mock(DaAccountSnapshotService::class);
        $snapshots->shouldReceive('captureOrFail')->andReturnUsing(function (Service $service): DaAccountSnapshot {
            return DaAccountSnapshot::query()->create([
                'service_id' => $service->id,
                'username' => 'da-user',
                'primary_domain' => $service->name,
                'site_count' => 1,
                'database_count' => 1,
                'mailbox_count' => 0,
                'ftp_count' => 0,
                'dns_record_count' => 2,
                'dns_imported' => true,
                'status' => 'captured',
                'payload' => ['zones' => []],
            ]);
        })->byDefault();
        $this->app->instance(DaAccountSnapshotService::class, $snapshots);

        return $convert;
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightOk(): array
    {
        return [
            'can_convert' => true,
            'blockers' => [],
            'detected_stack' => 'wordpress',
            'has_addon_sites' => false,
            'mailbox_count' => 0,
            'must_pull_mail' => false,
            'inventory' => [
                'domain' => 'ready.example.com',
                'addon_site_count' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightBlocked(string $message): array
    {
        return [
            'can_convert' => false,
            'blockers' => [$message],
            'detected_stack' => 'wordpress',
            'has_addon_sites' => false,
            'mailbox_count' => 0,
            'must_pull_mail' => false,
            'inventory' => [
                'domain' => 'blocked.example.com',
                'addon_site_count' => 0,
            ],
        ];
    }
}
