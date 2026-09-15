<?php

namespace Tests\Feature\Reseller;

use App\Enums\DaConvertBatchItemStatus;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\ContainerDeployment;
use App\Models\DaAccountSnapshot;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * A reseller moves their own DirectAdmin customers onto their own
 * Application Hosting plans from the reseller dashboard.
 */
class ResellerDaOfframpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reseller_opens_the_move_board_and_sees_only_their_own_accounts(): void
    {
        $reseller = $this->reseller();
        $mine = $this->daService($reseller, 'mine.example.com');
        $other = $this->daService($this->reseller(), 'theirs.example.com');
        $this->plan($reseller, 'Startup');

        $this->actingAs($reseller)
            ->get(route('reseller.directadmin-offramp'))
            ->assertOk()
            ->assertSee('Move to Application Hosting')
            ->assertSee($mine->name)
            ->assertDontSee($other->name)
            ->assertSee('Startup')
            ->assertSee('Move selected accounts');
    }

    public function test_the_services_page_points_at_the_board_when_directadmin_accounts_remain(): void
    {
        $reseller = $this->reseller();
        $this->daService($reseller, 'mine.example.com');

        $this->actingAs($reseller)
            ->get(route('reseller.services.index'))
            ->assertOk()
            ->assertSee('1 DirectAdmin account can move to Application Hosting')
            ->assertSee(route('reseller.directadmin-offramp'), false);
    }

    public function test_a_customer_cannot_open_the_board(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $response = $this->actingAs($customer)->get(route('reseller.directadmin-offramp'));
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_queueing_onto_a_reseller_plan_sizes_the_service_by_the_plan_and_records_the_reseller_as_actor(): void
    {
        Bus::fake();
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $ready = $this->daService($reseller, 'ready.example.com');
        $plan = $this->plan($reseller, 'Startup', cpu: 2, memoryMb: 4096, diskGb: 40);
        $this->bindConvertMock([$ready->id => $this->preflightOk('ready.example.com')]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['da:'.$ready->service_meta['username']],
                'plans' => ['da:'.$ready->service_meta['username'] => $plan->id],
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('reseller.directadmin-offramp'))
            ->assertSessionHas('success');

        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, function (ConvertDirectAdminServiceToContainerJob $job) use ($ready, $shell): bool {
            return $job->serviceId === $ready->id && $job->productId === $shell->id;
        });

        $ready->refresh();
        $this->assertSame($plan->id, (int) $ready->reseller_product_id);
        $this->assertSame(2.0, (float) $ready->service_meta['reseller_catalog_limits']['cpu']);
        $this->assertSame(4096, (int) $ready->service_meta['reseller_catalog_limits']['memory_mb']);
        $this->assertSame('queued', $ready->service_meta['da_convert']['status']);
        $this->assertSame($shell->id, (int) $ready->service_meta['da_convert']['target_product_id']);

        $batch = DaConvertBatch::query()->firstOrFail();
        $this->assertSame($reseller->id, (int) $batch->admin_user_id, 'The reseller is recorded as the actor.');
        $this->assertSame($reseller->id, (int) $batch->reseller_user_id);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'reseller.da_offramp_batch']);
    }

    public function test_a_plan_mapped_from_the_directadmin_package_is_used_when_no_choice_is_made(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $ready = $this->daService($reseller, 'ready.example.com');
        $ready->update(['service_meta' => array_merge($ready->service_meta, ['package_name' => 'Business'])]);
        $plan = $this->plan($reseller, 'Business', cpu: 1, memoryMb: 2048, packageName: 'Business');
        $this->bindConvertMock([$ready->id => $this->preflightOk('ready.example.com')]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['service:'.$ready->id],
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertSessionHas('success');

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class);
        $this->assertSame($plan->id, (int) $ready->fresh()->reseller_product_id);
    }

    public function test_an_account_with_no_plan_and_no_fallback_is_blocked_not_converted(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $ready = $this->daService($reseller, 'ready.example.com');
        $this->bindConvertMock([$ready->id => $this->preflightOk('ready.example.com')]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['service:'.$ready->id],
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertRedirect(route('reseller.directadmin-offramp'));

        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
        $item = DaConvertBatchItem::query()->firstOrFail();
        $this->assertSame(DaConvertBatchItemStatus::Blocked, $item->status);
        $this->assertStringContainsString('Choose an Application Hosting plan', (string) $item->error);
        $this->assertNull($ready->fresh()->service_meta['da_convert'] ?? null);
    }

    public function test_the_compute_pool_blocks_what_it_cannot_hold_and_lets_the_rest_through(): void
    {
        Bus::fake();
        $reseller = $this->reseller(['cpu_pool_cores' => 3, 'memory_pool_mb' => 6144]);
        $first = $this->daService($reseller, 'first.example.com');
        $second = $this->daService($reseller, 'second.example.com');
        $plan = $this->plan($reseller, 'Startup', cpu: 2, memoryMb: 4096);
        $this->bindConvertMock([
            $first->id => $this->preflightOk('first.example.com'),
            $second->id => $this->preflightOk('second.example.com'),
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['service:'.$first->id, 'service:'.$second->id],
                'reseller_product_id' => $plan->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertSessionHas('success');

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn ($job) => $job->serviceId === $first->id);
        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class, fn ($job) => $job->serviceId === $second->id);

        $blocked = DaConvertBatchItem::query()->where('service_id', $second->id)->firstOrFail();
        $this->assertSame(DaConvertBatchItemStatus::Blocked, $blocked->status);
        $this->assertStringContainsString('short by 1 vCPU and 2.0 GB RAM', (string) $blocked->error);
    }

    public function test_the_resellers_email_plan_is_used_for_the_mail_pull(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $ready = $this->daService($reseller, 'ready.example.com');
        $plan = $this->plan($reseller, 'Startup');
        $mailcow = Product::factory()->emailHosting()->create(['is_active' => true]);
        $emailPlan = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'product_id' => $mailcow->id,
            'name' => 'Mail Basic',
            'type' => 'email_hosting',
            'monthly_price' => 300,
            'is_active' => true,
        ]);
        $this->bindConvertMock([$ready->id => $this->preflightOk('ready.example.com')]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['service:'.$ready->id],
                'reseller_product_id' => $plan->id,
                'email_reseller_product_id' => $emailPlan->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertSame($mailcow->id, (int) DaConvertBatch::query()->firstOrFail()->email_product_id);
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn ($job) => $job->emailProductId === $mailcow->id);
    }

    public function test_another_resellers_plan_is_refused(): void
    {
        $reseller = $this->reseller();
        $ready = $this->daService($reseller, 'ready.example.com');
        $foreignPlan = $this->plan($this->reseller(), 'Not yours');

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['service:'.$ready->id],
                'reseller_product_id' => $foreignPlan->id,
                'confirm_silent' => '1',
            ])
            ->assertSessionHasErrors('reseller_product_id');
    }

    public function test_a_live_directadmin_user_not_yet_a_customer_is_created_under_the_reseller(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $plan = $this->plan($reseller, 'Startup');
        $this->bindLiveDirectAdmin($reseller, [[
            'username' => 'jamesk',
            'domain' => 'jameskahiga.com',
            'package' => 'Business',
            'email' => 'old@example.test',
            'name' => 'Panel',
            'suspended' => false,
        ]]);
        $this->bindConvertMock([], allowAny: true);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.store'), [
                'account_keys' => ['da:jamesk'],
                'reseller_product_id' => $plan->id,
                'acknowledge_mail_pull' => '1',
                'acknowledge_addon_sites' => '1',
                'confirm_silent' => '1',
            ])
            ->assertSessionHas('success');

        $customer = User::query()->where('email', 'info@jameskahiga.com')->firstOrFail();
        $this->assertSame($reseller->id, (int) $customer->reseller_id);
        $service = Service::query()->where('external_reference', 'jamesk')->firstOrFail();
        $this->assertSame($reseller->id, (int) $service->reseller_id);
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class);
    }

    public function test_cut_dns_on_another_resellers_batch_is_not_found(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $theirs = $this->daService($other, 'theirs.example.com');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $other->id,
            'admin_user_id' => $other->id,
            'product_id' => $shell->id,
            'status' => 'converting',
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $theirs->id,
            'product_id' => $shell->id,
            'status' => DaConvertBatchItemStatus::Converted,
            'hostname' => 'theirs.example.com',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.cut-dns', $batch), ['item_ids' => [$item->id]])
            ->assertNotFound();
    }

    public function test_the_board_shows_what_the_scan_found_on_a_moved_site(): void
    {
        $reseller = $this->reseller();
        $clean = $this->daService($reseller, 'clean.example.com');
        $dirty = $this->daService($reseller, 'dirty.example.com');
        foreach ([$clean, $dirty] as $service) {
            $service->update(['service_meta' => array_merge($service->service_meta, [
                'da_convert' => ['status' => 'failed', 'error' => 'boom', 'previous' => ['product_id' => $service->product_id]],
            ])]);
            ContainerDeployment::factory()->create(['service_id' => $service->id, 'status' => 'running']);
        }
        $clean->update(['service_meta' => array_merge($clean->service_meta, [
            'integrity_scan' => ['suspicious_count' => 0, 'exposed_count' => 0, 'quarantined_count' => 0],
        ])]);
        $dirty->update(['service_meta' => array_merge($dirty->service_meta, [
            'integrity_scan' => ['suspicious_count' => 2, 'exposed_count' => 1, 'quarantined_count' => 3],
            'security_incidents' => [['id' => 'abc', 'files' => 3]],
        ])]);
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $reseller->id,
            'admin_user_id' => $reseller->id,
            'product_id' => $shell->id,
            'status' => 'converting',
        ]);
        foreach ([$clean, $dirty] as $service) {
            DaConvertBatchItem::query()->create([
                'da_convert_batch_id' => $batch->id,
                'service_id' => $service->id,
                'product_id' => $shell->id,
                'status' => DaConvertBatchItemStatus::Failed,
                'hostname' => $service->name,
                'error' => 'boom',
            ]);
        }

        $this->actingAs($reseller)
            ->get(route('reseller.directadmin-offramp'))
            ->assertOk()
            ->assertSee('Clean')
            ->assertSee('2 files to review');

        $progress = $this->actingAs($reseller)->get(route('reseller.directadmin-offramp.progress'))->assertOk()->json();
        $labels = collect($progress['accounts'])->pluck('security.label')->all();
        $this->assertContains('Clean', $labels);
        $this->assertContains('2 files to review · 1 exposed backup', $labels);
    }

    private function reseller(array $packageOverrides = []): User
    {
        $package = ResellerPackage::create(array_merge([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 0,
            'max_services' => 0,
            'price' => 1000,
            'active' => true,
            'disk_pool_gb' => 100,
        ], $packageOverrides));

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function plan(User $reseller, string $name, float $cpu = 1, int $memoryMb = 1024, float $diskGb = 10, ?string $packageName = null): ResellerProduct
    {
        return ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'name' => $name,
            'type' => 'container_hosting',
            'direct_admin_package_name' => $packageName,
            'monthly_price' => 1500,
            'is_active' => true,
            'resource_limits' => ['cpu' => $cpu, 'memory_mb' => $memoryMb, 'disk_gb' => $diskGb],
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
        $da->shouldReceive('getAccountDirectoryEntries')->andReturnUsing(function (array $usernames) use ($entries): array {
            $wanted = array_fill_keys(array_map('strtolower', $usernames), true);

            return array_values(array_filter($entries, fn (array $entry) => isset($wanted[strtolower((string) $entry['username'])])));
        });
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
    private function bindConvertMock(array $preflights, bool $allowAny = false): void
    {
        $convert = Mockery::mock(DirectAdminToContainerConvertService::class);
        $convert->shouldReceive('preflight')->andReturnUsing(function (Service $service) use ($preflights, $allowAny): array {
            return $preflights[$service->id] ?? ($allowAny ? $this->preflightOk($service->name) : $this->preflightBlocked('Unexpected service.'));
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
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightOk(string $domain): array
    {
        return [
            'can_convert' => true,
            'blockers' => [],
            'detected_stack' => 'wordpress',
            'has_addon_sites' => false,
            'mailbox_count' => 0,
            'must_pull_mail' => false,
            'inventory' => ['domain' => $domain, 'addon_site_count' => 0],
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
            'inventory' => ['domain' => 'blocked.example.com', 'addon_site_count' => 0],
        ];
    }
}
