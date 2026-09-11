<?php

namespace Tests\Feature\Console;

use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One customer reporting "Error establishing a database connection" does not
 * mean one site carries the fuse. wp-config.php is written on first boot and
 * older deploys wrote the bare service name, which resolves to whichever
 * sidecar Docker picked on a node where every stack shares a network.
 *
 * The audit exists to say how many sites are in that state before they break,
 * so what it must never do is under-report or touch anything.
 */
class AuditWordPressDatabaseHostCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_bare_service_name_in_wp_config_is_reported_with_the_name_it_should_carry(): void
    {
        $this->wordPressDeployment('user-5-service-130-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'mysql']);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('user-5-service-130-wordpress-mysql')
            ->expectsOutputToContain('At risk: 1')
            ->expectsOutputToContain('Unreadable: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_site_already_pinned_to_its_own_sidecar_is_not_an_alarm(): void
    {
        $this->wordPressDeployment('user-5-service-131-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'user-5-service-131-wordpress-mysql']);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('pinned')
            ->expectsOutputToContain('At risk: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_config_that_cannot_be_read_is_counted_rather_than_called_healthy(): void
    {
        $this->wordPressDeployment('user-5-service-132-wordpress');
        $this->analyzerReturning([]);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('Unreadable: 1')
            ->expectsOutputToContain('At risk: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_node_that_cannot_be_reached_does_not_abandon_the_rest_of_the_fleet(): void
    {
        $broken = Node::factory()->containerHost()->create();
        $healthy = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-140-wordpress', $broken);
        $this->wordPressDeployment('user-5-service-141-wordpress', $healthy);

        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')
            ->andReturnUsing(function (SSHService $ssh, string $container) use ($broken): array {
                if (str_contains($container, 'service-140')) {
                    throw new SSHConnectionException($broken->hostname, 'Connection refused');
                }

                return ['DB_HOST' => 'mysql'];
            });
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('user-5-service-141-wordpress-mysql')
            ->expectsOutputToContain('At risk: 1')
            ->expectsOutputToContain('Unreadable: 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_dead_node_is_dialled_once_and_not_once_per_site(): void
    {
        // SSHService retries three times before it gives up, so re-dialling a
        // refused host for every site it carries is how an audit stops finishing.
        $node = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-190-wordpress', $node);
        $this->wordPressDeployment('user-5-service-191-wordpress', $node);
        $this->wordPressDeployment('user-5-service-192-wordpress', $node);

        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')
            ->once()
            ->andThrow(new SSHConnectionException($node->hostname, 'Connection refused'));
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('is unreachable')
            ->expectsOutputToContain('At risk: 0')
            ->expectsOutputToContain('Unreadable: 3')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_at_risk_switch_hides_the_healthy_rows_without_changing_the_counts(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-150-wordpress', $node);
        $this->wordPressDeployment('user-5-service-151-wordpress', $node);

        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')
            ->andReturnUsing(fn (SSHService $ssh, string $container): array => [
                'DB_HOST' => str_contains($container, 'service-150')
                    ? 'mysql'
                    : 'user-5-service-151-wordpress-mysql',
            ]);
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);

        $this->artisan('containers:audit-wordpress-db-host', ['--at-risk' => true])
            ->expectsOutputToContain('user-5-service-150-wordpress')
            ->doesntExpectOutputToContain('user-5-service-151-wordpress')
            ->expectsOutputToContain('At risk: 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_leaves_alone_every_stack_that_is_not_wordpress(): void
    {
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'nodejs'],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-5-service-160-nodejs',
            'status' => 'running',
        ]);

        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldNotReceive('effectiveCredentials');
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('No WordPress deployments to audit.')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_terminated_deployment_is_not_worth_waking_anybody_for(): void
    {
        $this->wordPressDeployment('user-5-service-170-wordpress', status: 'terminated');

        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldNotReceive('effectiveCredentials');
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);

        $this->artisan('containers:audit-wordpress-db-host')
            ->expectsOutputToContain('No WordPress deployments to audit.')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_node_filter_narrows_the_sweep_to_one_host(): void
    {
        $wanted = Node::factory()->containerHost()->create();
        $other = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-180-wordpress', $wanted);
        $this->wordPressDeployment('user-5-service-181-wordpress', $other);

        $this->analyzerReturning(['DB_HOST' => 'mysql']);

        $this->artisan('containers:audit-wordpress-db-host', ['--node' => $wanted->id])
            ->expectsOutputToContain('Auditing 1 WordPress deployment(s)')
            ->doesntExpectOutputToContain('user-5-service-181-wordpress')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function analyzerReturning(array $credentials): void
    {
        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')->andReturn($credentials);
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);
    }

    private function wordPressDeployment(
        string $containerName,
        ?Node $node = null,
        string $status = 'running',
    ): ContainerDeployment {
        $node ??= Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'wordpress'],
        ]);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $containerName,
            'status' => $status,
        ]);
    }
}
