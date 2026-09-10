<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerDoctorFrontendBuildAnalyzer;
use App\Services\SSH\SSHService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A single container that builds and serves its own export bakes public values
 * exactly like a split stack does, so it has to be diagnosed the same way.
 * Leaving it out is why an Expo web app kept reporting a saved setting as
 * missing with nothing in Doctor to act on.
 */
class ContainerDoctorFrontendBuildFindingsTest extends TestCase
{
    #[Test]
    public function it_reports_a_stale_bundle_on_a_single_container_export(): void
    {
        $findings = $this->findings(buildContainsValue: false);

        $this->assertCount(1, $findings);
        $this->assertSame('frontend_public_env_stale', $findings[0]['id']);
        $this->assertSame('rebuild_frontend_bundle', $findings[0]['treat_action']);
        $this->assertStringContainsString('EXPO_PUBLIC_API_URL', $findings[0]['summary']);
        $this->assertStringContainsString('the application build output', $findings[0]['evidence'][0]);
    }

    #[Test]
    public function it_stays_quiet_when_the_bundle_already_carries_the_value(): void
    {
        $this->assertSame([], $this->findings(buildContainsValue: true));
    }

    #[Test]
    public function it_stays_quiet_when_the_source_never_mentions_the_key(): void
    {
        $this->assertSame([], $this->findings(buildContainsValue: false, sourceReferencesKey: false));
    }

    #[Test]
    public function it_stays_quiet_until_something_has_been_exported(): void
    {
        $this->assertSame([], $this->findings(buildContainsValue: false, buildDirectoryExists: false));
    }

    #[Test]
    public function it_probes_the_pinned_application_root_rather_than_the_clone_root(): void
    {
        $commands = [];
        $this->findings(buildContainsValue: false, applicationRoot: 'apps/web', commands: $commands);

        $probed = implode("\n", $commands);
        $this->assertStringContainsString('/app/apps/web/dist', $probed);
    }

    /**
     * @param  list<string>  $commands
     * @return list<array<string, mixed>>
     */
    private function findings(
        bool $buildContainsValue,
        bool $sourceReferencesKey = true,
        bool $buildDirectoryExists = true,
        string $applicationRoot = '',
        array &$commands = [],
    ): array {
        $service = new Service([
            'name' => 'Mobile web',
            'service_meta' => $applicationRoot === '' ? [] : [
                'node_workloads' => ['topology' => 'single', 'backend' => ['root' => $applicationRoot]],
            ],
        ]);
        $service->id = 458;

        $deployment = new ContainerDeployment;
        $deployment->container_name = 'user-493-service-458-nodejs';
        $deployment->env_values = ['EXPO_PUBLIC_API_URL' => 'https://api.sameplan.example.com'];
        // No bound domains, so the site is plain http and an https API address
        // is reachable either way. Set explicitly to keep the probe off the DB.
        $deployment->setRelation('domains', new Collection);

        /** @var SSHService&MockInterface $ssh */
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command) use (&$commands, $buildContainsValue, $sourceReferencesKey, $buildDirectoryExists): string {
                $commands[] = $command;

                if (str_starts_with($command, 'test -d')) {
                    return $buildDirectoryExists && str_contains($command, '/dist') ? 'yes' : 'no';
                }

                if (str_contains($command, 'EXPO_PUBLIC_API_URL')) {
                    return $sourceReferencesKey ? 'yes' : 'no';
                }

                return $buildContainsValue ? 'yes' : 'no';
            }
        );

        return app(ContainerDoctorFrontendBuildAnalyzer::class)->findings($service, $deployment, $ssh);
    }
}
