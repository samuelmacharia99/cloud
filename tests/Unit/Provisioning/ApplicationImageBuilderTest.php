<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Services\Provisioning\ApplicationImageBuilder;
use App\Services\Provisioning\ContainerDeploymentEventRecorder;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Upstreams without a stable image tag are built on the node from a release
 * tag, once per host and ref, and every outcome leaves a deployment event.
 */
class ApplicationImageBuilderTest extends TestCase
{
    private const DEFINITIONS = [
        'ospos' => [
            'image' => 'ospos',
            'repository' => 'https://github.com/opensourcepos/opensourcepos.git',
            'default_ref' => '3.4.1',
        ],
    ];

    #[Test]
    public function the_reference_is_the_release_tag_under_the_platform_registry(): void
    {
        config(['containers.runtime_registry' => 'talksasa']);
        $builder = new ApplicationImageBuilder(definitions: self::DEFINITIONS);
        $template = new ContainerTemplate(['slug' => 'ospos']);

        $this->assertTrue($builder->usesApplicationImage($template));
        $this->assertFalse($builder->usesApplicationImage(new ContainerTemplate(['slug' => 'wordpress'])));

        $this->assertSame('talksasa/ospos:3.4.1', $builder->resolveImageReference($template)['image']);
        $this->assertSame('talksasa/ospos:3.4.0', $builder->resolveImageReference($template, '3.4.0')['image']);
        $this->assertSame('3.4.1', $builder->resolveImageReference($template, '../evil; rm -rf /')['ref'], 'an unsafe ref falls back to the default');
    }

    #[Test]
    public function an_image_already_on_the_node_is_not_rebuilt(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command) => str_contains($command, 'docker image inspect') ? 'yes' : null);
        $ssh->shouldNotReceive('mkdirp');
        $ssh->shouldNotReceive('upload');

        $events = Mockery::mock(ContainerDeploymentEventRecorder::class);
        $events->shouldNotReceive('record');

        $builder = new ApplicationImageBuilder($events, self::DEFINITIONS);
        $image = $builder->ensureImage($ssh, new ContainerTemplate(['slug' => 'ospos']), null, $this->service(), $this->deployment());

        $this->assertSame('talksasa/ospos:3.4.1', $image);
        $this->assertCount(1, $commands);
    }

    #[Test]
    public function a_missing_image_is_built_from_the_dockerfile_with_the_selected_ref(): void
    {
        config(['containers.runtime_build_path' => '/opt/talksasa/runtime-builds']);
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command) => str_contains($command, 'docker image inspect') ? 'no' : null);
        $ssh->shouldReceive('mkdirp')->once()->with('/opt/talksasa/runtime-builds/apps/ospos/3.4.0');
        $ssh->shouldReceive('upload')->once()->withArgs(function (string $content, string $path): bool {
            return str_contains($content, 'FROM php:8.2-apache') && $path === '/opt/talksasa/runtime-builds/apps/ospos/3.4.0/Dockerfile';
        });

        $recorded = [];
        $events = Mockery::mock(ContainerDeploymentEventRecorder::class);
        $events->shouldReceive('record')->andReturnUsing(function ($service, $deployment, string $event, array $payload) use (&$recorded) {
            $recorded[] = $event;
        });

        $builder = new ApplicationImageBuilder($events, self::DEFINITIONS);
        $image = $builder->ensureImage($ssh, new ContainerTemplate(['slug' => 'ospos']), '3.4.0', $this->service(), $this->deployment());

        $this->assertSame('talksasa/ospos:3.4.0', $image);
        $build = collect($commands)->first(fn (string $command) => str_contains($command, 'docker build'));
        $this->assertNotNull($build);
        $this->assertStringContainsString("--build-arg OSPOS_REF='3.4.0'", $build);
        $this->assertStringContainsString("-t 'talksasa/ospos:3.4.0'", $build);
        $this->assertStringContainsString("cd '/opt/talksasa/runtime-builds/apps/ospos/3.4.0'", $build);
        $this->assertSame([ApplicationImageBuilder::EVENT_STARTED, ApplicationImageBuilder::EVENT_SUCCEEDED], $recorded);
    }

    #[Test]
    public function a_failed_build_is_recorded_and_rethrown(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands, function (string $command) {
            if (str_contains($command, 'docker image inspect')) {
                return 'no';
            }
            if (str_contains($command, 'docker build')) {
                throw new \RuntimeException('npm run build failed');
            }

            return null;
        });
        $ssh->shouldReceive('mkdirp');
        $ssh->shouldReceive('upload');

        $recorded = [];
        $events = Mockery::mock(ContainerDeploymentEventRecorder::class);
        $events->shouldReceive('record')->andReturnUsing(function ($service, $deployment, string $event, array $payload) use (&$recorded) {
            $recorded[$event] = $payload;
        });

        $builder = new ApplicationImageBuilder($events, self::DEFINITIONS);

        try {
            $builder->ensureImage($ssh, new ContainerTemplate(['slug' => 'ospos']), null, $this->service(), $this->deployment());
            $this->fail('expected the build failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('npm run build failed', $e->getMessage());
        }

        $this->assertArrayHasKey(ApplicationImageBuilder::EVENT_FAILED, $recorded);
        $this->assertSame('npm run build failed', $recorded[ApplicationImageBuilder::EVENT_FAILED]['error']);
    }

    #[Test]
    public function templates_that_are_not_built_here_are_untouched(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');

        $builder = new ApplicationImageBuilder(definitions: self::DEFINITIONS);
        $image = $builder->ensureImage($ssh, new ContainerTemplate(['slug' => 'wordpress', 'docker_image' => 'wordpress:6']), null, $this->service());

        $this->assertSame('wordpress:6', $image);
    }

    private function service(): Service
    {
        $service = new Service;
        $service->id = 30;

        return $service;
    }

    private function deployment(): ContainerDeployment
    {
        $deployment = new ContainerDeployment(['node_id' => 7]);
        $deployment->id = 12;

        return $deployment;
    }

    /**
     * @param  list<string>  $commands
     */
    private function ssh(array &$commands, ?callable $answer = null): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $answer): string {
            $commands[] = $command;

            return (string) ($answer ? $answer($command) : '');
        });

        return $ssh;
    }
}
