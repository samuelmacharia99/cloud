<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The probe that guards a split web/API deploy used to chain three assertions
 * with `;`, so the shell returned only the last one's status and the two
 * routing checks never affected the verdict. Every curl wrote to /dev/null and
 * every grep was quiet, so a failure arrived as the command text and "exited
 * with status 1" — one message for a setup page, a dead upstream, and an
 * application returning 500.
 */
class SplitStackReadinessProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function a_stack_that_routes_and_answers_is_ready(): void
    {
        $this->assertNull($this->describe([
            'front_status' => '200',
            'front_upstream' => 'frontend',
            'api_status' => '200',
            'api_upstream' => 'backend',
        ]));
    }

    #[Test]
    public function a_site_root_that_reaches_the_wrong_upstream_fails(): void
    {
        // This is the assertion that could never fail before. An edge routing
        // every request to the backend passed the old probe outright.
        $failure = $this->describe([
            'front_status' => '200',
            'front_upstream' => 'backend',
            'api_status' => '200',
            'api_upstream' => 'backend',
        ]);

        $this->assertNotNull($failure);
        $this->assertStringContainsString('not routed to the frontend', $failure);
        $this->assertStringContainsString('backend', $failure);
    }

    #[Test]
    public function an_api_path_that_reaches_the_frontend_fails(): void
    {
        $failure = $this->describe([
            'front_status' => '200',
            'front_upstream' => 'frontend',
            'api_status' => '200',
            'api_upstream' => 'frontend',
        ]);

        $this->assertNotNull($failure);
        $this->assertStringContainsString('/api/health was not routed to the backend', $failure);
    }

    #[Test]
    public function a_backend_that_answers_with_a_server_error_is_named_as_such(): void
    {
        // Service 457. Routing was correct all along; the application was
        // returning 500 because its database had no schema.
        $failure = $this->describe([
            'front_status' => '200',
            'front_upstream' => 'frontend',
            'api_status' => '500',
            'api_upstream' => 'backend',
        ]);

        $this->assertNotNull($failure);
        $this->assertStringContainsString('routed correctly', $failure);
        $this->assertStringContainsString('HTTP 500', $failure);
    }

    #[Test]
    public function the_setup_page_reads_as_a_hold_rather_than_a_routing_fault(): void
    {
        // The edge answers on its own behalf while settings are outstanding.
        // Under the old probe this was indistinguishable from a broken backend,
        // and sent people debugging routing that was never wrong.
        $failure = $this->describe([
            'front_status' => '503',
            'front_upstream' => 'setup',
            'api_status' => '503',
            'api_upstream' => 'setup',
        ]);

        $this->assertNotNull($failure);
        $this->assertStringContainsString('setup page', $failure);
        $this->assertStringContainsString('waiting for its settings', $failure);
    }

    #[Test]
    public function an_edge_that_never_answered_says_so(): void
    {
        $failure = $this->describe([
            'front_status' => '',
            'front_upstream' => '',
            'api_status' => '',
            'api_upstream' => '',
        ]);

        $this->assertNotNull($failure);
        $this->assertStringContainsString('did not answer', $failure);
    }

    #[Test]
    public function an_accepted_4xx_from_the_backend_is_not_a_failure(): void
    {
        // A health route behind auth answers 401, and the stack is fine.
        foreach (['401', '403', '404', '405', '422', '301'] as $status) {
            $this->assertNull($this->describe([
                'front_status' => '200',
                'front_upstream' => 'frontend',
                'api_status' => $status,
                'api_upstream' => 'backend',
            ]), 'HTTP '.$status.' should not fail readiness.');
        }
    }

    #[Test]
    public function the_probe_reads_four_facts_out_of_one_round_trip(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn(
            "front_status=200\nfront_upstream=Frontend\napi_status=500\napi_upstream=Backend\n"
        );

        $probe = app(ContainerDeploymentService::class)->probeSplitStack($ssh, 30004);

        $this->assertSame(
            [
                'front_status' => '200',
                'front_upstream' => 'frontend',
                'api_status' => '500',
                'api_upstream' => 'backend',
            ],
            $probe,
        );
    }

    #[Test]
    public function a_probe_that_returned_nothing_is_read_as_no_answer(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->once()->andReturn('');

        $probe = app(ContainerDeploymentService::class)->probeSplitStack($ssh, 30004);

        $this->assertSame('', $probe['api_status']);
        $this->assertNotNull(app(ContainerDeploymentService::class)->describeSplitStackProbe($probe));
    }

    /**
     * @param  array{front_status: string, front_upstream: string, api_status: string, api_upstream: string}  $probe
     */
    private function describe(array $probe): ?string
    {
        return app(ContainerDeploymentService::class)->describeSplitStackProbe($probe);
    }
}
