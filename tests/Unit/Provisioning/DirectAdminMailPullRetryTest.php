<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailcowService;
use App\Services\SSH\SSHService;
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

    public function test_retry_updates_an_existing_sync_job_with_the_rotated_password_and_recopies_maildirs(): void
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
        // provision() clamps the domain to the plan's single mailbox; the pull must raise it for both.
        $client->shouldReceive('editDomain')
            ->once()
            ->with('example.com', Mockery::on(fn (array $attr) => $attr['mailboxes'] === '7' && (int) $attr['quota'] >= 7 * 1024))
            ->andReturn(['success' => true, 'message' => 'OK']);
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
        $provisioning->shouldReceive('limitsForProduct')->andReturn(['mailboxes' => 1, 'aliases' => 20, 'quota_mb' => 5120, 'mailbox_quota_mb' => 1024, 'msgs_per_day' => 500]);
        $provisioning->shouldReceive('generateMailboxPassword')->andReturn('rotated-pw');
        $provisioning->shouldReceive('resolveNode')->andReturn($mailcowNode);

        /** @var DirectAdminToMailcowMigrationService&MockInterface $migrator */
        $migrator = Mockery::mock(DirectAdminToMailcowMigrationService::class, [$provisioning, app(DirectAdminMailPullProgress::class)])->makePartial();
        $migrator->shouldReceive('mailcowSshProbeError')->once()->andReturn(null);
        $migrator->shouldReceive('setDirectAdminMailboxPassword')->twice()->andReturn(true);
        // A retry rewrites: both maildirs are copied again, including the one copied last time.
        $migrator->shouldReceive('copyDirectAdminMaildirToMailcow')
            ->twice()
            ->with(Mockery::any(), Mockery::any(), 'exampleuser', 'example.com', Mockery::anyOf('info', 'sales'), Mockery::anyOf('info@example.com', 'sales@example.com'), Mockery::any())
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
        $this->assertSame(['sales@example.com (sync: exists but could not be updated: sync job not found by mailbox)'], $result['failed_mailboxes']);
        $this->assertSame(['info@example.com', 'sales@example.com'], $daService->fresh()->service_meta['mailcow_migration']['mailboxes_seen']);

        $log = app(DirectAdminMailPullProgress::class)->snapshot($daService->fresh())['log'];
        $this->assertStringContainsString('Mailcow domain example.com allows 7 mailbox(es)', $log);
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
        $client->shouldReceive('editDomain')->once()->andReturn(['success' => true, 'message' => 'OK']);
        $client->shouldReceive('addMailbox')->once()->andReturn(['success' => true, 'message' => 'OK']);
        $client->shouldReceive('addSyncJob')->once()->andReturn(['success' => true, 'message' => 'OK']);

        $provisioning = Mockery::mock(MailcowProvisioningService::class);
        $provisioning->shouldReceive('provision')->once();
        $provisioning->shouldReceive('clientForService')->andReturn($client);
        $provisioning->shouldReceive('limitsForProduct')->andReturn(['mailboxes' => 1, 'aliases' => 20, 'quota_mb' => 5120, 'mailbox_quota_mb' => 1024, 'msgs_per_day' => 500]);
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

    public function test_probe_names_a_missing_ssh_username_before_connecting(): void
    {
        $node = Node::factory()->mailcow()->create(['name' => 'mail-1', 'ip_address' => '203.0.113.9', 'ssh_username' => null, 'ssh_password' => 'secret']);

        $message = app(DirectAdminToMailcowMigrationService::class)->mailcowSshProbeError($node);

        $this->assertStringContainsString('Mailcow node "mail-1" (203.0.113.9) has no SSH username', $message);

        $node->update(['ssh_username' => 'root', 'ssh_password' => null, 'ssh_private_key' => null]);
        $this->assertStringContainsString('has no SSH password or private key', app(DirectAdminToMailcowMigrationService::class)->mailcowSshProbeError($node->fresh()));
    }

    public function test_ssh_service_refuses_a_node_without_a_username_before_opening_a_socket(): void
    {
        $node = Node::factory()->mailcow()->create(['ssh_username' => '', 'ssh_password' => 'secret']);

        $this->expectException(SSHCommandException::class);
        $this->expectExceptionMessage('SSH username is not set on this node');

        SSHService::forNode($node)->exec('true', 5, retry: false);
    }

    public function test_mailcow_create_refusals_reach_the_console_and_the_summary(): void
    {
        $daNode = Node::factory()->directAdmin()->create(['hostname' => 'da.example.net']);
        $mailcowNode = Node::factory()->mailcow()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $daService = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'node_id' => $daNode->id,
            'service_meta' => ['domain' => 'example.com', 'da_legacy' => ['username' => 'exampleuser', 'da_node_id' => $daNode->id, 'domain' => 'example.com']],
        ]);

        $client = Mockery::mock(MailcowService::class);
        $client->shouldReceive('editDomain')->once()->andReturn(['success' => false, 'message' => 'access denied']);
        $client->shouldReceive('addMailbox')->twice()->andReturn(['success' => false, 'message' => 'max_mailbox_exceeded']);
        $client->shouldReceive('listMailboxes')->andReturn(['success' => true, 'data' => []]);

        $provisioning = Mockery::mock(MailcowProvisioningService::class);
        $provisioning->shouldReceive('provision')->once();
        $provisioning->shouldReceive('clientForService')->andReturn($client);
        $provisioning->shouldReceive('limitsForProduct')->andReturn(['mailboxes' => 1, 'aliases' => 20, 'quota_mb' => 5120, 'mailbox_quota_mb' => 1024, 'msgs_per_day' => 500]);
        $provisioning->shouldReceive('generateMailboxPassword')->andReturn('pw');
        $provisioning->shouldReceive('resolveNode')->andReturn($mailcowNode);

        /** @var DirectAdminToMailcowMigrationService&MockInterface $migrator */
        $migrator = Mockery::mock(DirectAdminToMailcowMigrationService::class, [$provisioning, app(DirectAdminMailPullProgress::class)])->makePartial();
        $migrator->shouldReceive('mailcowSshProbeError')->once()->andReturn(null);
        $migrator->shouldNotReceive('copyDirectAdminMaildirToMailcow');

        $result = $migrator->pullFromDirectAdminUser($daService, $emailProduct, [
            'example.com' => [
                ['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com'],
                ['account' => 'sales', 'email' => 'sales@example.com', 'domain' => 'example.com'],
            ],
        ], ['pull_mail' => true, 'da_imap_host' => 'da.example.net']);

        $this->assertSame(['info@example.com (create: max_mailbox_exceeded)', 'sales@example.com (create: max_mailbox_exceeded)'], $result['failed_mailboxes']);
        $this->assertStringContainsString('2 mailbox(es) refused by Mailcow: max_mailbox_exceeded.', $result['message']);
        $log = app(DirectAdminMailPullProgress::class)->snapshot($daService->fresh())['log'];
        $this->assertStringContainsString('Could not raise the Mailcow mailbox limit on example.com: access denied', $log);
        $this->assertStringContainsString('info@example.com Mailcow create failed: max_mailbox_exceeded', $log);
    }

    public function test_retry_lists_the_account_live_and_adds_mailboxes_seen_before(): void
    {
        $daNode = Node::factory()->directAdmin()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $emailService = Service::factory()->create(['product_id' => $emailProduct->id, 'provisioning_driver_key' => 'mailcow']);
        $daService = Service::factory()->create([
            'node_id' => $daNode->id,
            'service_meta' => [
                'domain' => 'example.com',
                'da_legacy' => ['username' => 'exampleuser', 'da_node_id' => $daNode->id, 'domain' => 'example.com', 'email_service_id' => $emailService->id],
                'mailcow_migration' => ['email_service_id' => $emailService->id, 'mailboxes_created' => ['info@example.com'], 'mailboxes_seen' => ['info@example.com', 'old@example.com']],
            ],
        ]);

        /** @var DirectAdminToMailcowMigrationService&MockInterface $migrator */
        $migrator = Mockery::mock(DirectAdminToMailcowMigrationService::class, [Mockery::mock(MailcowProvisioningService::class), app(DirectAdminMailPullProgress::class)])->makePartial();
        $migrator->shouldReceive('listMailboxesViaSsh')->once()->andReturn(['by_domain' => [
            'example.com' => [
                ['account' => 'info', 'email' => 'info@example.com', 'domain' => 'example.com'],
                ['account' => 'sales', 'email' => 'sales@example.com', 'domain' => 'example.com'],
            ],
        ], 'all' => []]);
        $captured = null;
        $migrator->shouldReceive('pullFromDirectAdminUser')
            ->once()
            ->withArgs(function ($service, $product, array $byDomain) use (&$captured) {
                $captured = $byDomain;

                return true;
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        $migrator->retryMailContentPull($daService);

        $emails = array_map(fn ($box) => $box['email'], $captured['example.com']);
        sort($emails);
        $this->assertSame(['info@example.com', 'old@example.com', 'sales@example.com'], $emails);
    }

    public function test_each_pull_run_starts_a_fresh_terminal_with_the_previous_outcome_on_top(): void
    {
        $service = Service::factory()->create();
        $progress = app(DirectAdminMailPullProgress::class);

        $progress->begin($service, 2);
        $progress->log($service, 'first run detail');
        $progress->complete($service, 'First run: 1 need review');

        $state = $progress->begin($service, 9);

        $this->assertSame(2, $state['run']);
        $this->assertStringStartsWith('[', $state['log']);
        $this->assertStringContainsString('── mail pull run 2 ── previous run completed: First run: 1 need review', $state['log']);
        $this->assertStringContainsString('Pulling 9 mailbox(es)', $state['log']);
        $this->assertStringNotContainsString('first run detail', $state['log']);
        $this->assertSame(2, $state['percent']);
    }
}
