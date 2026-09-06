<?php

namespace Tests\Feature\Admin;

use App\Enums\DaConvertBatchItemStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\ContainerDeployment;
use App\Models\DaAccountSnapshot;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\ResellerDirectAdminService;
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
            ->assertSee('Queue selected converts')
            ->assertSee('Import DA packages');
    }

    public function test_admin_imports_directadmin_packages_into_reseller_catalog(): void
    {
        [$admin, $reseller] = $this->board();
        Product::factory()->containerHosting()->create(['name' => 'App Hosting Medium']);
        $da = Mockery::mock(ResellerDirectAdminService::class);
        $da->shouldReceive('listAssignablePackages')->andReturn([
            'packages' => [['name' => 'Business', 'disk_quota' => 15, 'description' => 'DA']],
            'error' => null,
        ]);
        $this->app->instance(ResellerDirectAdminService::class, $da);

        $this->actingAs($admin)
            ->post(route('admin.resellers.directadmin-offramp.import-packages', $reseller))
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHas('success');

        $listing = ResellerProduct::query()->where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame('Business', $listing->direct_admin_package_name);
        $this->assertSame('container_hosting', $listing->type);
    }

    public function test_queue_uses_the_listing_container_size_and_keeps_reseller_price(): void
    {
        Bus::fake();
        [$admin, $reseller, $ready, , $fallback] = $this->board();
        $ready->update([
            'custom_price' => 3200,
            'billing_cycle' => 'monthly',
            'service_meta' => array_merge($ready->service_meta ?? [], ['package_name' => 'Business']),
        ]);
        $listingEngine = Product::factory()->containerHosting()->create(['name' => 'App Hosting Large']);
        $listing = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'product_id' => $listingEngine->id,
            'name' => 'Business',
            'type' => 'container_hosting',
            'direct_admin_package_name' => 'Business',
            'monthly_price' => 3200,
            'is_active' => true,
        ]);
        $this->bindConvertMock($fallback, [
            $ready->id => $this->preflightOk(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'service_ids' => [$ready->id],
                'product_id' => $fallback->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller));

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, function (ConvertDirectAdminServiceToContainerJob $job) use ($ready, $listingEngine): bool {
            return $job->serviceId === $ready->id && $job->productId === $listingEngine->id;
        });
        $ready->refresh();
        $this->assertEquals(3200, (float) $ready->custom_price);
        $this->assertSame($listing->id, (int) $ready->reseller_product_id);
        $this->assertSame($listing->id, (int) ($ready->service_meta['reseller_product_id'] ?? 0));
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

        $this->actingAs($customer)
            ->post(route('admin.resellers.directadmin-offramp.import-packages', $reseller))
            ->assertForbidden();

        $this->actingAs($customer)
            ->getJson(route('admin.resellers.directadmin-offramp.progress', $reseller))
            ->assertForbidden();
    }

    public function test_admin_can_poll_live_convert_progress(): void
    {
        [$admin, $reseller, $ready] = $this->board();
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $reseller->id,
            'admin_user_id' => $admin->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => 'converting',
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $ready->id,
            'hostname' => 'ready.example.com',
            'status' => DaConvertBatchItemStatus::Converting,
        ]);
        $ready->update([
            'service_meta' => array_merge($ready->service_meta ?? [], [
                'da_convert' => [
                    'status' => 'running',
                    'steps' => ['Exporting site files from DirectAdmin', 'Provisioning container'],
                    'heartbeat_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.resellers.directadmin-offramp.progress', $reseller))
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('current.item_id', $item->id)
            ->assertJsonPath('current.hostname', 'ready.example.com')
            ->assertSee('Exporting site files from DirectAdmin', false);

        $this->actingAs($admin)
            ->get(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertOk()
            ->assertSee('da-convert')
            ->assertSee('Watch convert');
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

    public function test_progress_polling_does_not_rate_limit_cut_dns(): void
    {
        [$admin, $reseller] = $this->board();
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $reseller->id,
            'admin_user_id' => $admin->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => 'ready_for_cutover',
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $this->daService($reseller, 'cut.example.com')->id,
            'status' => 'waiting_dns',
        ]);

        $this->actingAs($admin);

        for ($i = 0; $i < 15; $i++) {
            $this->getJson(route('admin.resellers.directadmin-offramp.progress', $reseller))->assertOk();
        }

        $this->post(route('admin.resellers.directadmin-offramp.cut-dns', [$reseller, $batch]), [
            'item_ids' => [$item->id],
        ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHas('success');
    }

    public function test_offramp_lists_directadmin_users_that_are_not_on_the_platform(): void
    {
        [$admin, $reseller] = $this->board();
        $this->bindLiveDirectAdmin($reseller, [[
            'username' => 'jamesk',
            'domain' => 'jameskahiga.com',
            'package' => 'Business',
            'email' => null,
            'name' => null,
            'suspended' => false,
        ]]);

        $this->actingAs($admin)
            ->get(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertOk()
            ->assertSee('jamesk')
            ->assertSee('jameskahiga.com')
            ->assertSee('Jameskah')
            ->assertSee('info@jameskahiga.com')
            ->assertSee('Not on Talksasa yet');
    }

    public function test_offramp_hides_accounts_already_on_a_cloudflare_container(): void
    {
        [$admin, $reseller] = $this->board();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $containerProduct = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $containerProduct->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'name' => 'settled.example.com',
            'external_reference' => 'settleduser',
            'service_meta' => ['username' => 'settleduser', 'domain' => 'settled.example.com'],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
            'domain' => 'settled.example.com',
        ]);
        Domain::query()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'settled.example',
            'extension' => '.com',
            'type' => 'registration',
            'status' => 'active',
            'expires_at' => now()->addYear(),
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'cf-zone-settled',
            'nameserver_1' => 'ada.ns.cloudflare.com',
            'nameserver_2' => 'bob.ns.cloudflare.com',
        ]);
        $this->bindLiveDirectAdmin($reseller, [[
            'username' => 'settleduser',
            'domain' => 'settled.example.com',
            'package' => 'Business',
            'email' => null,
            'name' => null,
            'suspended' => false,
        ]]);

        $this->actingAs($admin)
            ->get(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertOk()
            ->assertDontSee('settleduser')
            ->assertDontSee('settled.example.com');
    }

    public function test_queue_creates_a_customer_and_service_for_an_unlinked_da_account(): void
    {
        Bus::fake();
        [$admin, $reseller, , , $container] = $this->board();
        $this->bindLiveDirectAdmin($reseller, [[
            'username' => 'jamesk',
            'domain' => 'jameskahiga.com',
            'package' => 'Business',
            'email' => 'old@example.test',
            'name' => 'Panel',
            'suspended' => false,
        ]]);
        ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'type' => 'shared_hosting',
            'name' => 'Business',
            'direct_admin_package_name' => 'Business',
            'monthly_price' => 2000,
            'yearly_price' => 20000,
            'is_active' => true,
        ]);
        $this->bindConvertMock($container, [], true);

        $this->actingAs($admin)
            ->post(route('admin.resellers.directadmin-offramp.store', $reseller), [
                'account_keys' => ['da:jamesk'],
                'product_id' => $container->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('admin.resellers.directadmin-offramp', $reseller))
            ->assertSessionHas('success');

        $customer = User::query()->where('email', 'info@jameskahiga.com')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Jameskah', $customer->name);
        $this->assertSame($reseller->id, $customer->reseller_id);
        $this->assertTrue(Service::query()->where('external_reference', 'jamesk')->exists());
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class);
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
     * @param  list<array{username: string, domain: ?string, package: ?string, email: ?string, name: ?string, suspended: bool}>  $entries
     */
    private function bindLiveDirectAdmin(User $reseller, array $entries): void
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'api_url' => 'https://da.example.test:2222',
            'is_active' => true,
        ]);
        $reseller->forceFill([
            'directadmin_username' => 'res_acme',
            'directadmin_login_key' => 'login-key',
            'reseller_node_id' => $node->id,
            'country' => 'KE',
        ])->save();

        $usernames = array_map(fn (array $entry): string => strtolower((string) $entry['username']), $entries);
        $da = Mockery::mock(DirectAdminService::class);
        $da->shouldReceive('listUsersOwnedByReseller')->andReturn($usernames);
        $da->shouldReceive('getAccountDirectoryEntry')->andReturnUsing(function (string $username) use ($entries): ?array {
            foreach ($entries as $entry) {
                if (strtolower((string) $entry['username']) === strtolower($username)) {
                    return $entry;
                }
            }

            return null;
        });

        $this->mock(ResellerDirectAdminService::class, function ($mock) use ($da, $node) {
            $mock->shouldReceive('hasDirectAdminBinding')->andReturn(true);
            $mock->shouldReceive('directAdmin')->andReturn($da);
            $mock->shouldReceive('resolveNode')->andReturn($node);
            $mock->shouldReceive('listAssignablePackages')->andReturn(['packages' => [], 'error' => null])->byDefault();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $preflights
     */
    private function bindConvertMock(Product $container, array $preflights, bool $allowAny = false): DirectAdminToContainerConvertService
    {
        $convert = Mockery::mock(DirectAdminToContainerConvertService::class);
        $convert->shouldReceive('applicationHostingCatalog')->andReturn([
            'products' => collect([$container]),
            'recommended' => collect(),
            'fallback' => true,
        ]);
        $convert->shouldReceive('preflight')->andReturnUsing(function (Service $service) use ($preflights, $allowAny): array {
            return $preflights[$service->id] ?? ($allowAny ? $this->preflightOk() : $this->preflightBlocked('Unexpected service.'));
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
