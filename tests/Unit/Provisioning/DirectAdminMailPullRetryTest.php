<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailcowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DirectAdminMailPullRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailcow_client_finds_and_edits_the_sync_job_for_a_mailbox(): void
    {
        Http::fake([
            'https://mail.example.com/api/v1/get/syncjobs/all/no_log' => Http::response([
                ['id' => 3, 'user2' => 'other@example.com', 'host1' => 'da.example.net'],
                ['id' => 7, 'user2' => 'Info@Example.com', 'host1' => 'da.example.net'],
            ]),
            'https://mail.example.com/api/v1/edit/syncjob' => Http::response([
                ['type' => 'success', 'msg' => ['object_modified', 7]],
            ]),
        ]);
        $client = new MailcowService(Node::factory()->mailcow()->create());

        $job = $client->findSyncJobForMailbox('info@example.com');
        $this->assertSame(7, $job['id']);
        $this->assertNull($client->findSyncJobForMailbox('nobody@example.com'));

        $edited = $client->editSyncJob(7, ['password1' => 'new-secret', 'active' => '1']);
        $this->assertTrue($edited['success']);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/v1/edit/syncjob')
                && $request['items'] === ['7']
                && $request['attr']['password1'] === 'new-secret';
        });
    }

    public function test_retry_updates_an_existing_sync_job_with_the_rotated_password_and_skips_copied_maildirs(): void
    {
        $daNode = Node::factory()->directAdmin()->create(['hostname' => 'da.example.net']);
        $mailcowNode = Node::factory()->mailcow()->create();
        $user = User::factory()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $daService = Service::factory()->create([
            'user_id' => $user->id,
            'node_id' => $daNode->id,
            'service_meta' => [
                'domain' => 'example.com',
                'da_legacy' => ['username' => 'exampleuser', 'da_node_id' => $daNode->id, 'domain' => 'example.com'],
                'mailcow_migration' => ['copied_maildirs' => ['info@example.com']],
            ],
        ]);

        $client = Mockery::mock(MailcowService::class);
        $client->shouldReceive('addMailbox')->twice()->andReturn(['success' => true, 'message' => 'OK']);
        $client->shouldReceive('addSyncJob')->twice()->andReturn(['success' => false, 'message' => 'object_exists']);
        $client->shouldReceive('findSyncJobForMailbox')->with('info@example.com')->once()->andReturn(['id' => 7, 'user2' => 'info@example.com']);
        $client->shouldReceive('findSyncJobForMailbox')->with('sales@example.com')->once()->andReturn(null);
        $client->shouldReceive('editSyncJob')
            ->once()
            ->with(7, Mockery::on(fn (array $attr) => $attr['password1'] === 'rotated-pw' && $attr['host1'] === 'da.example.net' && $attr['user1'] === 'info@example.com'))
            ->andReturn(['success' => true, 'message' => 'OK']);

        $provisioning = Mockery::mock(MailcowProvisioningService::class);
        $provisioning->shouldReceive('provision')->once();
        $provisioning->shouldReceive('clientForService')->andReturn($client);
        $provisioning->shouldReceive('limitsForProduct')->andReturn(['mailboxes' => 10, 'mailbox_quota_mb' => 1024]);
        $provisioning->shouldReceive('generateMailboxPassword')->andReturn('rotated-pw');
        $provisioning->shouldReceive('resolveNode')->andReturn($mailcowNode);

        /** @var DirectAdminToMailcowMigrationService&MockInterface $migrator */
        $migrator = Mockery::mock(DirectAdminToMailcowMigrationService::class, [$provisioning, app(DirectAdminMailPullProgress::class)])->makePartial();
        $migrator->shouldReceive('mailcowSshProbeError')->once()->andReturn(null);
        $migrator->shouldReceive('setDirectAdminMailboxPassword')->twice()->andReturn(true);
        // Only the mailbox not yet copied is copied.
        $migrator->shouldReceive('copyDirectAdminMaildirToMailcow')
            ->once()
            ->with(Mockery::any(), Mockery::any(), 'exampleuser', 'example.com', 'sales', 'sales@example.com', Mockery::any())
            ->andReturn(true);

        $result = $migrator->pullFromDirectAdminUser($daService, $emailProduct, [
            'example.com' => [
                ['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com'],
                ['account' => 'sales', 'email' => 'sales@example.com', 'domain' => 'example.com'],
            ],
        ], ['pull_mail' => true, 'da_imap_host' => 'da.example.net']);

        $this->assertTrue($result['success']);
        $this->assertSame(['info@example.com', 'sales@example.com'], $result['copied_maildirs']);
        $this->assertSame(['info@example.com'], $result['sync_jobs']);
        $this->assertSame(['sales@example.com (sync)'], $result['failed_mailboxes']);

        $log = app(DirectAdminMailPullProgress::class)->snapshot($daService->fresh())['log'];
        $this->assertStringContainsString('info@example.com maildir already copied on an earlier pull; skipping', $log);
        $this->assertStringContainsString('info@example.com IMAP sync job already exists; updated its DirectAdmin password', $log);
        $this->assertStringContainsString('sales@example.com IMAP sync job exists but could not be updated', $log);
    }

    public function test_a_failed_mailcow_ssh_probe_skips_every_copy_and_names_the_fix(): void
    {
        $daNode = Node::factory()->directAdmin()->create(['hostname' => 'da.example.net']);
        $mailcowNode = Node::factory()->mailcow()->create(['name' => 'mail-1', 'ip_address' => '203.0.113.9']);
        $emailProduct = Product::factory()->emailHosting()->create();
        $daService = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'node_id' => $daNode->id,
            'service_meta' => [
                'domain' => 'example.com',
                'da_legacy' => ['username' => 'exampleuser', 'da_node_id' => $daNode->id, 'domain' => 'example.com'],
            ],
        ]);

        $client = Mockery::mock(MailcowService::class);
        $client->shouldReceive('addMailbox')->once()->andReturn(['success' => true, 'message' => 'OK']);
        $client->shouldReceive('addSyncJob')->once()->andReturn(['success' => true, 'message' => 'OK']);

        $provisioning = Mockery::mock(MailcowProvisioningService::class);
        $provisioning->shouldReceive('provision')->once();
        $provisioning->shouldReceive('clientForService')->andReturn($client);
        $provisioning->shouldReceive('limitsForProduct')->andReturn(['mailboxes' => 10, 'mailbox_quota_mb' => 1024]);
        $provisioning->shouldReceive('generateMailboxPassword')->andReturn('rotated-pw');
        $provisioning->shouldReceive('resolveNode')->andReturn($mailcowNode);

        $blocker = 'Mailcow node "mail-1" SSH login failed at 203.0.113.9 (SSH authentication failed). Fix its SSH user, port or key under Admin → Nodes, then Retry mail pull; maildir copies were skipped.';
        /** @var DirectAdminToMailcowMigrationService&MockInterface $migrator */
        $migrator = Mockery::mock(DirectAdminToMailcowMigrationService::class, [$provisioning, app(DirectAdminMailPullProgress::class)])->makePartial();
        $migrator->shouldReceive('mailcowSshProbeError')->once()->andReturn($blocker);
        $migrator->shouldReceive('setDirectAdminMailboxPassword')->once()->andReturn(true);
        $migrator->shouldNotReceive('copyDirectAdminMaildirToMailcow');

        $result = $migrator->pullFromDirectAdminUser($daService, $emailProduct, [
            'example.com' => [['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com']],
        ], ['pull_mail' => true, 'da_imap_host' => 'da.example.net']);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['copied_maildirs']);
        $this->assertSame(['info@example.com'], $result['sync_jobs']);
        $this->assertStringContainsString('Mailcow node "mail-1" SSH login failed at 203.0.113.9', $result['message']);
        $this->assertStringContainsString('IMAP sync jobs are in place', $result['message']);
        $log = app(DirectAdminMailPullProgress::class)->snapshot($daService->fresh())['log'];
        $this->assertStringContainsString('FAILED: Mailcow node "mail-1" SSH login failed', $log);
        $this->assertStringContainsString('info@example.com maildir copy skipped: Mailcow node SSH login failed', $log);
    }
}
