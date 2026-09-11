<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saving settings from the console is a synchronous web request, and the
 * verification after the stack came back waited three minutes before answering.
 * PHP gave up first, so the customer watched a spinner and then read that their
 * save had failed. It had not: the values were written and the stack was
 * already running on them.
 */
class ContainerEnvironmentApplyBudgetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_verification_budget_fits_inside_a_web_request(): void
    {
        // A number a customer will sit through, and well under any sane PHP
        // max_execution_time. The full three minutes belongs to a queued deploy.
        $budget = (int) config('containers.application_readiness.apply_timeout_seconds');

        $this->assertGreaterThanOrEqual(10, $budget);
        $this->assertLessThanOrEqual(60, $budget);
        $this->assertLessThan(
            (int) config('containers.application_readiness.timeout_seconds'),
            $budget,
            'A web request must not wait as long as a background deploy.'
        );
    }

    #[Test]
    public function a_slow_start_is_reported_as_applied_rather_than_failed(): void
    {
        [$service] = $this->deployedService();
        $this->applyReturning(false);

        $result = app(ContainerEnvironmentService::class)
            ->updateVariables($service, [['key' => 'APP_ENV', 'value' => 'production']], restart: true);

        $this->assertTrue($result['applied']);
        $this->assertStringContainsString('saved and applied', $result['message']);
        $this->assertStringContainsString('had not finished starting', $result['message']);
    }

    #[Test]
    public function a_confirmed_start_says_so_plainly(): void
    {
        [$service] = $this->deployedService();
        $this->applyReturning(true);

        $result = app(ContainerEnvironmentService::class)
            ->updateVariables($service, [['key' => 'APP_ENV', 'value' => 'production']], restart: true);

        $this->assertStringContainsString('applied to the running stack', $result['message']);
        $this->assertStringNotContainsString('had not finished starting', $result['message']);
    }

    #[Test]
    public function a_genuine_apply_failure_is_still_an_error(): void
    {
        // The distinction that matters: "not confirmed yet" is not "broken".
        [$service] = $this->deployedService();

        $deployments = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployments->shouldReceive('applyEnvironmentVariables')
            ->andThrow(new \RuntimeException('Container host is not available.'));
        $this->app->instance(ContainerDeploymentService::class, $deployments);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('were saved');

        app(ContainerEnvironmentService::class)
            ->updateVariables($service, [['key' => 'APP_ENV', 'value' => 'production']], restart: true);
    }

    private function applyReturning(bool $confirmed): void
    {
        $deployments = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployments->shouldReceive('applyEnvironmentVariables')->andReturn($confirmed);
        $this->app->instance(ContainerDeploymentService::class, $deployments);
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function deployedService(): array
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'python'],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python',
            'status' => 'running',
            'env_values' => ['DB_HOST' => 'db'],
        ]);

        return [$service->fresh(), $deployment];
    }
}
