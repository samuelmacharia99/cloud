<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use App\Services\Provisioning\ContainerNodeVersionService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerNodeVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContainerNodeVersionService $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = app(ContainerNodeVersionService::class);
    }

    #[Test]
    public function it_reads_engines_node_before_volta(): void
    {
        $package = json_encode([
            'engines' => ['node' => '>=22 <25'],
            'volta' => ['node' => '20.11.0'],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('>=22 <25', $this->versions->constraintFromPackageJson($package));
    }

    #[Test]
    public function it_selects_the_lowest_compatible_lts_and_preserves_image_flavor(): void
    {
        $allowed = ContainerTemplate::nodeRuntimeVersions();

        $this->assertSame('22-slim', $this->versions->selectVersion('>=22', '20-slim', $allowed));
        $this->assertSame('24-alpine', $this->versions->selectVersion('>=24', '22-alpine', $allowed));
        $this->assertSame('20-alpine', $this->versions->selectVersion('>=18', '20-alpine', $allowed));
    }

    #[Test]
    public function it_supports_common_semver_ranges(): void
    {
        $this->assertTrue($this->versions->majorSatisfies(20, '^20.9.0'));
        $this->assertTrue($this->versions->majorSatisfies(22, '>=20.9 <23'));
        $this->assertTrue($this->versions->majorSatisfies(24, '20.x || >=24'));
        $this->assertFalse($this->versions->majorSatisfies(20, '>=22'));
    }

    #[Test]
    public function it_rejects_unavailable_or_ambiguous_constraints(): void
    {
        $this->expectException(\DomainException::class);
        $this->versions->selectVersion('>=26', '24-alpine', ContainerTemplate::nodeRuntimeVersions());
    }

    #[Test]
    public function it_rejects_invalid_package_json_instead_of_guessing(): void
    {
        $this->expectException(\DomainException::class);
        $this->versions->constraintFromPackageJson('{broken');
    }

    #[Test]
    public function automatic_detection_persists_a_compatible_runtime_before_build(): void
    {
        [$service, $deployment] = $this->nodeDeployment(['node_version_source' => 'auto']);
        $ssh = $this->nodeProjectSsh('>=22 <24');
        $appDirectory = $this->createMock(ContainerAppDirectoryService::class);
        $appDirectory->method('hostAppPath')->willReturn('/opt/talksasa/app');
        $versions = new ContainerNodeVersionService(new ContainerApplicationRuntimeService, $appDirectory);

        $result = $versions->reconcileFromHost($service, $deployment, $ssh);

        $this->assertSame('22-alpine', $result['selected_version']);
        $this->assertTrue($result['changed']);
        $this->assertSame('22-alpine', $deployment->fresh()->selected_version);
        $this->assertSame('detected', $service->fresh()->service_meta['node_version_source']);
        $this->assertSame('>=22 <24', $service->fresh()->service_meta['node_detected_engine']);
    }

    #[Test]
    public function incompatible_manual_pin_is_rejected_without_changing_the_deployment(): void
    {
        [$service, $deployment] = $this->nodeDeployment([
            'selected_version' => '20-alpine',
            'node_version_source' => 'manual',
        ], '20-alpine');
        $ssh = $this->nodeProjectSsh('>=22');
        $appDirectory = $this->createMock(ContainerAppDirectoryService::class);
        $appDirectory->method('hostAppPath')->willReturn('/opt/talksasa/app');
        $versions = new ContainerNodeVersionService(new ContainerApplicationRuntimeService, $appDirectory);

        try {
            $versions->reconcileFromHost($service, $deployment, $ssh);
            $this->fail('Expected the incompatible manual pin to be rejected.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('manually pinned to 20-alpine', $e->getMessage());
        }

        $this->assertSame('20-alpine', $deployment->fresh()->selected_version);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function nodeDeployment(array $meta, ?string $selectedVersion = '20-alpine'): array
    {
        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'docker_image' => 'node:20-alpine',
            'versions' => ContainerTemplate::nodeRuntimeVersions(),
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $node = Node::factory()->create(['type' => 'container_host']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'service_meta' => $meta,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'node-version-test-'.$service->id,
            'selected_version' => $selectedVersion,
        ]);

        return [$service->fresh(['product.containerTemplate']), $deployment];
    }

    private function nodeProjectSsh(string $constraint): SSHService
    {
        $packageJson = json_encode([
            'engines' => ['node' => $constraint],
            'scripts' => ['start' => 'next start'],
            'dependencies' => ['next' => '15.0.0'],
        ], JSON_THROW_ON_ERROR);

        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(
            static function (string $command) use ($packageJson): string {
                if (str_contains($command, 'head -c') && str_contains($command, 'package.json')) {
                    return $packageJson;
                }
                if (str_contains($command, 'package.json') && str_contains($command, 'echo yes')) {
                    return 'yes';
                }

                return 'no';
            }
        );

        return $ssh;
    }
}
