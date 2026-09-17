<?php

namespace Tests\Feature\Reseller;

use App\Enums\DaConvertBatchItemStatus;
use App\Enums\DaConvertBatchStatus;
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
use App\Services\DomainInputParser;
use App\Services\Provisioning\DaAccountSnapshotService;
use App\Services\Provisioning\DaConvertRepullService;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerProvisionProductResolver;
use Carbon\Carbon;
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

        // The customer's portal lists domain rows, so linking made one.
        $domain = Domain::query()->where('name', 'jameskahiga')->first();
        $this->assertNotNull($domain);
        $this->assertSame($customer->id, (int) $domain->user_id);
        $this->assertSame($reseller->id, (int) $domain->reseller_id);
        $this->assertSame('dns', $domain->type);
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

        // Partial with real dependencies: the snapshot capture is faked, the
        // domain row helper runs for real so the customer's portal gets its row.
        $snapshots = Mockery::mock(DaAccountSnapshotService::class, [
            app(DirectAdminToContainerMigrationService::class),
            app(DomainInputParser::class),
            app(DirectAdminToMailcowMigrationService::class),
        ])->makePartial();
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

    public function test_a_stalled_queued_convert_is_restarted_from_the_console(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $service = $this->daService($reseller, 'simbacementfactory.com');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($reseller, $service, $shell, DaConvertBatchItemStatus::Queued);
        $service->update(['service_meta' => array_merge($service->service_meta, ['da_convert' => [
            'status' => 'queued',
            'mode' => 'convert_in_place',
            'queued_at' => now()->subMinutes(25)->toIso8601String(),
            'target_product_id' => $shell->id,
            'target_product_name' => $shell->name,
        ]])]);

        // Five newer batches, the way a day of retries leaves them: the queued
        // item still has to be in the console and the board must offer Restart.
        for ($i = 0; $i < 5; $i++) {
            $this->batchItem($reseller, $this->daService($reseller, "later-{$i}.example.com"), $shell, DaConvertBatchItemStatus::Blocked);
        }

        $progress = $this->actingAs($reseller)
            ->getJson(route('reseller.directadmin-offramp.progress'))
            ->assertOk()
            ->assertJsonPath('current.item_id', $item->id)
            ->assertJsonPath('current.can_restart', true)
            ->assertJsonPath('current.restart_url', route('reseller.directadmin-offramp.restart', [$batch, $item]));
        $this->assertContains($item->id, array_column($progress->json('items'), 'item_id'));
        $boardRow = collect($progress->json('accounts'))->firstWhere('service_id', $service->id);
        $this->assertTrue((bool) ($boardRow['can_restart'] ?? false), 'the board row offers Restart for a stalled queued item');
        $this->assertSame($item->id, (int) $boardRow['cutover_item_id']);

        $this->actingAs($reseller)
            ->get(route('reseller.directadmin-offramp'))
            ->assertOk()
            ->assertSee('restartRow(', false);
        $batchesBefore = DaConvertBatch::query()->count();
        $itemsBefore = DaConvertBatchItem::query()->count();

        $this->actingAs($reseller)
            ->postJson(route('reseller.directadmin-offramp.restart', [$batch, $item]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item_id', $item->id);

        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, function (ConvertDirectAdminServiceToContainerJob $job) use ($service, $shell, $item): bool {
            return $job->serviceId === $service->id
                && $job->productId === $shell->id
                && $job->batchItemId === $item->id
                && $job->acknowledgeExtraMailboxes === true;
        });

        $service->refresh();
        $this->assertSame('queued', $service->service_meta['da_convert']['status']);
        $this->assertSame(1, (int) $service->service_meta['da_convert']['attempt']);
        $this->assertTrue(now()->subMinute()->lt(Carbon::parse($service->service_meta['da_convert']['queued_at'])), 'queued_at is refreshed so the stall clock restarts');
        $this->assertSame(DaConvertBatchItemStatus::Queued, $item->fresh()->status);
        $this->assertSame(DaConvertBatchStatus::Converting, $batch->fresh()->status);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'reseller.da_offramp_restart']);

        // No batch or item was created: the same row runs again.
        $this->assertSame($batchesBefore, DaConvertBatch::query()->count());
        $this->assertSame($itemsBefore, DaConvertBatchItem::query()->count());
    }

    public function test_a_convert_that_is_still_moving_cannot_be_restarted(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $service = $this->daService($reseller, 'moving.example.com');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($reseller, $service, $shell, DaConvertBatchItemStatus::Converting);
        $service->update(['service_meta' => array_merge($service->service_meta, ['da_convert' => [
            'status' => 'running',
            'started_at' => now()->subMinutes(3)->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
            'steps' => ['Exporting site files from DirectAdmin'],
            'target_product_id' => $shell->id,
        ]])]);

        $progress = $this->actingAs($reseller)
            ->getJson(route('reseller.directadmin-offramp.progress'))
            ->assertOk()
            ->assertJsonPath('current.can_restart', false);
        $boardRow = collect($progress->json('accounts'))->firstWhere('service_id', $service->id);
        $this->assertFalse((bool) ($boardRow['can_restart'] ?? false), 'a live convert has no Restart on the board');

        $this->actingAs($reseller)
            ->postJson(route('reseller.directadmin-offramp.restart', [$batch, $item]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
        $this->assertSame('running', $service->fresh()->service_meta['da_convert']['status']);
    }

    public function test_a_blocked_convert_restarts_through_preflight_as_a_new_batch(): void
    {
        Bus::fake();
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $service = $this->daService($reseller, 'blocked.example.com');
        $plan = $this->plan($reseller, 'Starter');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($reseller, $service, $shell, DaConvertBatchItemStatus::Blocked, $plan);
        $item->update(['error' => 'Could not capture DNS for: blocked.example.com: DirectAdmin API HTTP 401']);
        $this->bindConvertMock([$service->id => $this->preflightOk('blocked.example.com')]);

        $this->actingAs($reseller)
            ->getJson(route('reseller.directadmin-offramp.progress'))
            ->assertOk()
            ->assertJsonPath('current.can_restart', true);

        $response = $this->actingAs($reseller)
            ->postJson(route('reseller.directadmin-offramp.restart', [$batch, $item]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $newBatch = DaConvertBatch::query()->where('id', '!=', $batch->id)->firstOrFail();
        $newItem = $newBatch->items()->firstOrFail();
        $this->assertSame($newBatch->id, (int) $response->json('batch_id'));
        $this->assertSame($newItem->id, (int) $response->json('item_id'));
        $this->assertSame(DaConvertBatchItemStatus::Queued, $newItem->status);
        $this->assertSame($plan->id, (int) $newItem->reseller_product_id, 'the plan the account was queued on is kept');
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn (ConvertDirectAdminServiceToContainerJob $job): bool => $job->batchItemId === $newItem->id);
    }

    public function test_another_resellers_convert_cannot_be_restarted(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $other = $this->reseller();
        $service = $this->daService($other, 'theirs.example.com');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($other, $service, $shell, DaConvertBatchItemStatus::Queued);

        $this->actingAs($reseller)
            ->postJson(route('reseller.directadmin-offramp.restart', [$batch, $item]))
            ->assertNotFound();

        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
    }

    /**
     * @return array{0: DaConvertBatch, 1: DaConvertBatchItem}
     */
    private function batchItem(User $reseller, Service $service, Product $engine, DaConvertBatchItemStatus $status, ?ResellerProduct $listing = null): array
    {
        $batch = DaConvertBatch::query()->create([
            'reseller_user_id' => $reseller->id,
            'admin_user_id' => $reseller->id,
            'product_id' => $engine->id,
            'acknowledge_mail_pull' => true,
            'acknowledge_addon_sites' => true,
            'status' => $status === DaConvertBatchItemStatus::Blocked ? DaConvertBatchStatus::Failed : DaConvertBatchStatus::Converting,
        ]);
        $item = DaConvertBatchItem::query()->create([
            'da_convert_batch_id' => $batch->id,
            'service_id' => $service->id,
            'product_id' => $engine->id,
            'reseller_product_id' => $listing?->id,
            'hostname' => $service->name,
            'status' => $status,
            'detected_stack' => 'wordpress',
        ]);

        return [$batch, $item];
    }

    public function test_a_blocked_account_is_pointed_at_the_right_directadmin_user_and_retried_at_once(): void
    {
        Bus::fake();
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $service = $this->daService($reseller, 'neodesignassociatesltd.co.ke');
        $plan = $this->plan($reseller, 'Starter');
        $node = $this->bindRelinkDirectAdmin($reseller, [
            'wambuiesther' => ['creator' => 'res_acme', 'suspended' => 'no', 'domain' => 'neodesignassociatesltd.co.ke'],
        ], ['wambuiesther']);
        $service->update(['node_id' => $node->id, 'credentials' => json_encode(['username' => 'wambuiesther712', 'password' => 'old'])]);
        $oldUsername = $service->service_meta['username'];
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($reseller, $service, $shell, DaConvertBatchItemStatus::Blocked, $plan);
        $item->update(['error' => 'DirectAdmin on Lani rejected login as wambuiesther712 (HTTP 401 Not logged in).']);
        $this->bindConvertMock([$service->id => $this->preflightOk('neodesignassociatesltd.co.ke')]);

        $this->actingAs($reseller)
            ->get(route('reseller.directadmin-offramp'))
            ->assertOk()
            ->assertSee('Fix DirectAdmin login')
            ->assertSee('wambuiesther', false);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.relink', $service), [
                'directadmin_username' => 'WambuiEsther',
                'node_id' => $node->id,
                'retry' => '1',
            ])
            ->assertRedirect(route('reseller.directadmin-offramp'))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame('wambuiesther', $service->service_meta['username']);
        $this->assertSame('wambuiesther', $service->external_reference);
        $this->assertSame($node->id, (int) $service->node_id);
        $this->assertSame('wambuiesther', json_decode((string) $service->credentials, true)['username']);
        $this->assertSame($oldUsername, $service->service_meta['da_relink'][0]['from_username']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'reseller.da_offramp_relink']);

        $newBatch = DaConvertBatch::query()->where('id', '!=', $batch->id)->firstOrFail();
        $newItem = $newBatch->items()->firstOrFail();
        $this->assertSame(DaConvertBatchItemStatus::Queued, $newItem->status);
        $this->assertSame($plan->id, (int) $newItem->reseller_product_id, 'the plan from the blocked attempt is kept');
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn (ConvertDirectAdminServiceToContainerJob $job): bool => $job->serviceId === $service->id && $job->batchItemId === $newItem->id);
    }

    public function test_a_directadmin_user_created_by_another_reseller_login_cannot_be_linked(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $service = $this->daService($reseller, 'theirs.example.com');
        $node = $this->bindRelinkDirectAdmin($reseller, [
            'someoneelse' => ['creator' => 'other_reseller', 'suspended' => 'no', 'domain' => 'theirs.example.com'],
            'ghost' => null,
        ]);
        $service->update(['node_id' => $node->id]);
        $before = $service->fresh()->service_meta['username'];

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.relink', $service), ['directadmin_username' => 'someoneelse', 'node_id' => $node->id])
            ->assertRedirect(route('reseller.directadmin-offramp'))
            ->assertSessionHasErrors('error');
        $this->assertStringContainsString('not created by your reseller login', session('errors')->first('error'));

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.relink', $service), ['directadmin_username' => 'ghost', 'node_id' => $node->id])
            ->assertSessionHasErrors('error');
        $this->assertStringContainsString('no DirectAdmin user ghost', session('errors')->first('error'));

        $this->assertSame($before, $service->fresh()->service_meta['username']);
        $this->assertSame(0, DaConvertBatch::query()->count());
        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
    }

    public function test_a_username_already_on_another_row_is_refused_and_another_resellers_service_is_not_found(): void
    {
        Bus::fake();
        $reseller = $this->reseller();
        $service = $this->daService($reseller, 'simbacementfactory.com');
        $twin = $this->daService($reseller, 'simba-twin.example.com');
        $node = $this->bindRelinkDirectAdmin($reseller, [
            $twin->service_meta['username'] => ['creator' => 'res_acme', 'suspended' => 'no', 'domain' => 'simbacementfactory.com'],
        ]);
        $service->update(['node_id' => $node->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.relink', $service), ['directadmin_username' => $twin->service_meta['username'], 'node_id' => $node->id])
            ->assertSessionHasErrors('error');
        $this->assertStringContainsString('already linked to service #'.$twin->id, session('errors')->first('error'));

        $other = $this->reseller();
        $theirs = $this->daService($other, 'other.example.com');
        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.relink', $theirs), ['directadmin_username' => 'anything', 'node_id' => $node->id])
            ->assertNotFound();

        Bus::assertNotDispatched(ConvertDirectAdminServiceToContainerJob::class);
    }

    /**
     * The reseller's DirectAdmin login is bound to a node, and the node's admin
     * API answers SHOW_USER_CONFIG for the given users (null = no such user).
     *
     * @param  array<string, ?array<string, string>>  $users
     * @param  list<string>  $liveUsernames
     */
    private function bindRelinkDirectAdmin(User $reseller, array $users, array $liveUsernames = []): Node
    {
        $node = Node::factory()->create([
            'name' => 'Lani',
            'type' => 'directadmin',
            'api_url' => 'https://da.example.test:2222',
            'is_active' => true,
        ]);
        $reseller->forceFill([
            'directadmin_username' => 'res_acme',
            'directadmin_login_key' => 'login-key',
            'reseller_node_id' => $node->id,
        ])->save();

        $admin = Mockery::mock(DirectAdminService::class);
        $admin->shouldReceive('getAccountLiveStatus')->andReturnUsing(function (string $username) use ($users): array {
            $username = strtolower($username);
            if (! array_key_exists($username, $users) || $users[$username] === null) {
                return ['live_status' => 'terminated', 'label' => 'Account not found on DirectAdmin', 'detail' => ['username' => $username]];
            }
            $row = $users[$username];

            return [
                'live_status' => ($row['suspended'] ?? 'no') === 'yes' ? 'suspended' : 'active',
                'label' => 'Active on DirectAdmin',
                'detail' => ['username' => $username, 'creator' => $row['creator'] ?? null, 'domain' => $row['domain'] ?? null, 'suspended' => $row['suspended'] ?? 'no'],
            ];
        });

        $resellerDa = Mockery::mock(DirectAdminService::class);
        $resellerDa->shouldReceive('listUsersOwnedByReseller')->andReturn($liveUsernames);
        $resellerDa->shouldReceive('getAccountDirectoryEntries')->andReturnUsing(fn (array $names): array => array_map(
            fn (string $name): array => ['username' => $name, 'domain' => $users[$name]['domain'] ?? null, 'package' => null, 'email' => null, 'name' => null, 'suspended' => false],
            $names
        ));

        $this->mock(ResellerDirectAdminService::class, function ($mock) use ($node, $admin, $resellerDa) {
            $mock->shouldReceive('resolveNode')->andReturn($node);
            $mock->shouldReceive('adminDirectAdmin')->andReturn($admin);
            $mock->shouldReceive('hasDirectAdminBinding')->andReturn(true);
            $mock->shouldReceive('directAdmin')->andReturn($resellerDa);
            $mock->shouldReceive('listAssignablePackages')->andReturn(['packages' => [], 'error' => null])->byDefault();
        });

        return $node;
    }

    public function test_a_finished_convert_is_wiped_and_pulled_again_only_with_the_confirmation(): void
    {
        Bus::fake();
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $service = $this->daService($reseller, 'www.whsafaris.co.ke');
        $plan = $this->plan($reseller, 'Starter');
        $shell = app(ResellerProvisionProductResolver::class)->shellContainerProduct();
        [$batch, $item] = $this->batchItem($reseller, $service, $shell, DaConvertBatchItemStatus::Done, $plan);
        $this->bindConvertMock([$service->id => $this->preflightOk('www.whsafaris.co.ke')]);
        $this->mock(DaConvertRepullService::class, function ($mock) {
            $mock->shouldReceive('wipeForRepull')->once()->andReturn(['removed_siblings' => 3, 'container' => 'user-1-service-77-wordpress']);
            $mock->shouldReceive('assess')->andReturn(['ok' => true, 'blockers' => [], 'siblings' => 3]);
        });

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.repull', $service), [])
            ->assertRedirect(route('reseller.directadmin-offramp'))
            ->assertSessionHasErrors('error');
        $this->assertStringContainsString('Tick the confirmation', session('errors')->first('error'));

        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.repull', $service), ['confirm_wipe' => '1'])
            ->assertRedirect(route('reseller.directadmin-offramp'))
            ->assertSessionHas('success');
        $this->assertStringContainsString('3 sibling site(s)', session('success'));
        $this->assertStringContainsString('Cut web DNS again', session('success'));

        $fresh = DaConvertBatch::query()->where('id', '!=', $batch->id)->firstOrFail();
        $newItem = $fresh->items()->firstOrFail();
        $this->assertSame(DaConvertBatchItemStatus::Queued, $newItem->status);
        $this->assertSame($plan->id, (int) $newItem->reseller_product_id, 'the plan of the first convert is kept');
        Bus::assertDispatched(ConvertDirectAdminServiceToContainerJob::class, fn (ConvertDirectAdminServiceToContainerJob $job): bool => $job->batchItemId === $newItem->id);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'reseller.da_offramp_repull']);

        $other = $this->reseller();
        $theirs = $this->daService($other, 'theirs.example.com');
        $this->actingAs($reseller)
            ->post(route('reseller.directadmin-offramp.repull', $theirs), ['confirm_wipe' => '1'])
            ->assertNotFound();
    }
}
