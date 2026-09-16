<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\WordPressAdminAccountService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class WordPressAdminAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resets_the_password_through_wordpress_and_keeps_the_platform_records_truthful(): void
    {
        [$owner, $service, $deployment] = $this->wordPressSite();
        $commands = [];
        $ssh = $this->sshAnswering($commands, 'TALKASA_WP_ADMIN_ID=7', 'TALKASA_WP_ADMIN_RESULT={"id":7,"login":"siteowner","email":"owner@example.test"}');

        $result = app(WordPressAdminAccountService::class)->resetPassword($service, $owner, null, $ssh);

        $this->assertTrue($result['generated']);
        $this->assertSame(20, strlen($result['password']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $result['password']);
        $this->assertSame('siteowner', $result['username']);
        $this->assertSame(7, $result['user_id']);

        $this->assertCount(2, $commands);
        $this->assertStringContainsString('wp-load.php', $commands[0]);
        $this->assertStringContainsString("-e 'TALKASA_WP_NEW_PASSWORD=".$result['password']."'", $commands[1]);
        $this->assertStringContainsString('wp_set_password', $commands[1]);
        $this->assertStringContainsString('$id = 7;', $commands[1]);

        $service->refresh();
        $credentials = json_decode((string) $service->credentials, true);
        $this->assertSame('siteowner', $credentials['admin_username']);
        $this->assertSame('owner@example.test', $credentials['admin_email']);
        $this->assertSame($result['password'], $credentials['admin_password']);
        $this->assertSame(7, $service->service_meta['wordpress_admin']['user_id']);
        $this->assertNotEmpty($service->service_meta['wordpress_admin']['password_reset_at']);

        $env = $deployment->fresh()->env_values;
        $this->assertSame($result['password'], $env['WORDPRESS_ADMIN_PASSWORD']);
        $this->assertSame('siteowner', $env['WORDPRESS_ADMIN_USER']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'wordpress.admin_password_reset']);
    }

    #[Test]
    public function a_chosen_password_is_used_as_given_and_a_short_one_never_reaches_the_site(): void
    {
        [$owner, $service] = $this->wordPressSite();
        $commands = [];
        $ssh = $this->sshAnswering($commands, 'TALKASA_WP_ADMIN_ID=3', 'TALKASA_WP_ADMIN_RESULT={"id":3,"login":"admin","email":"a@b.test"}');

        $result = app(WordPressAdminAccountService::class)->resetPassword($service, $owner, 'Correct Horse Battery 42', $ssh);
        $this->assertFalse($result['generated']);
        $this->assertSame('Correct Horse Battery 42', $result['password']);
        $this->assertStringContainsString("'TALKASA_WP_NEW_PASSWORD=Correct Horse Battery 42'", $commands[1]);

        $untouched = [];
        $quiet = $this->sshAnswering($untouched, '', '');
        try {
            app(WordPressAdminAccountService::class)->resetPassword($service, $owner, 'short', $quiet);
            $this->fail('expected the short password to be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('between 12 and 128', $e->getMessage());
        }
        $this->assertSame([], $untouched, 'nothing ran on the site');
    }

    #[Test]
    public function it_changes_the_admin_email_and_surfaces_what_wordpress_refuses(): void
    {
        [$owner, $service, $deployment] = $this->wordPressSite();
        $commands = [];
        $ssh = $this->sshAnswering($commands, 'TALKASA_WP_ADMIN_ID=7', 'TALKASA_WP_ADMIN_RESULT={"id":7,"login":"siteowner","email":"new@example.test"}');

        $result = app(WordPressAdminAccountService::class)->updateEmail($service, $owner, 'New@Example.test', $ssh);
        $this->assertSame('new@example.test', $result['email']);
        $this->assertStringContainsString("-e 'TALKASA_WP_NEW_EMAIL=new@example.test'", $commands[1]);
        $this->assertSame('new@example.test', $deployment->fresh()->env_values['WORDPRESS_ADMIN_EMAIL']);
        $this->assertSame('new@example.test', json_decode((string) $service->fresh()->credentials, true)['admin_email']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'wordpress.admin_email_changed']);

        $refused = [];
        $ssh = $this->sshAnswering($refused, 'TALKASA_WP_ADMIN_ID=7', 'TALKASA_WP_ADMIN_ERROR=Another WordPress user already has that email');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Another WordPress user already has that email');
        app(WordPressAdminAccountService::class)->updateEmail($service, $owner, 'taken@example.test', $ssh);
    }

    #[Test]
    public function it_refuses_a_stopped_site_and_a_site_with_no_administrator(): void
    {
        [$owner, $service, $deployment] = $this->wordPressSite();

        $none = [];
        $ssh = $this->sshAnswering($none, "TALKASA_WP_ADMIN_DIAG={\"multisite\":0,\"users\":0,\"cap_rows\":0,\"meta_key\":\"wp_capabilities\",\"roles\":[]}\nTALKASA_WP_ADMIN_ID=0", '');
        try {
            app(WordPressAdminAccountService::class)->resetPassword($service, $owner, null, $ssh);
            $this->fail('expected no administrator to stop the reset');
        } catch (RuntimeException $e) {
            $this->assertCount(1, $none, 'only the probe ran');
        }

        $deployment->update(['status' => 'stopped']);
        $stopped = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Start the site');
        app(WordPressAdminAccountService::class)->resetPassword($service->fresh(), $owner, null, $this->sshAnswering($stopped, '', ''));
    }

    #[Test]
    public function the_panel_reads_what_the_platform_knows_and_is_absent_for_other_stacks(): void
    {
        [, $service, $deployment] = $this->wordPressSite();
        $service->update(['credentials' => json_encode(['admin_username' => 'admin', 'admin_email' => 'a@b.test', 'admin_password' => 'x'])]);

        $panel = app(WordPressAdminAccountService::class)->panelState($service->fresh(), $deployment);
        $this->assertSame('admin', $panel['admin_username']);
        $this->assertSame('a@b.test', $panel['admin_email']);
        $this->assertTrue($panel['known']);
        $this->assertTrue($panel['container_running']);
        $this->assertNull($panel['password_reset_at']);

        $laravel = ContainerTemplate::query()->where('slug', 'laravel')->first() ?? ContainerTemplate::factory()->create(['slug' => 'laravel']);
        $other = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create(['container_template_id' => $laravel->id])->id,
        ]);
        $this->assertNull(app(WordPressAdminAccountService::class)->panelState($other->fresh(['product.containerTemplate']), null));
    }

    /**
     * @return array{0: User, 1: Service, 2: ContainerDeployment}
     */
    private function wordPressSite(): array
    {
        $owner = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress']);
        $product = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $node = Node::factory()->create(['type' => 'container_host', 'ip_address' => '10.0.0.9']);
        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'name' => 'example.test',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$owner->id.'-service-'.$service->id.'-wordpress',
            'env_values' => ['WORDPRESS_ADMIN_USER' => 'admin', 'WORDPRESS_ADMIN_EMAIL' => 'a@b.test', 'WORDPRESS_ADMIN_PASSWORD' => 'initial'],
        ]);

        return [$owner, $service->fresh(['product.containerTemplate', 'containerDeployment.node']), $deployment];
    }

    /**
     * @param  list<string>  $commands
     */
    private function sshAnswering(array &$commands, string $probeOutput, string $changeOutput): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $probeOutput, $changeOutput): string {
            $commands[] = $command;

            return str_contains($command, 'TALKASA_WP_NEW_') || str_contains($command, 'wp_set_password') ? $changeOutput : $probeOutput;
        });
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();

        return $ssh;
    }
}
