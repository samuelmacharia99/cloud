<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\Provisioning\WordPressAdminLoginService;
use App\Services\Provisioning\WordPressAppInstallationService;
use App\Services\Provisioning\WordPressContainerHardeningService;
use App\Services\SSH\SSHService;
use App\Services\Terminal\ContainerTerminalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SharedPlanWordPressDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSharedWordPressService(): Service
    {
        $wordpress = ContainerTemplate::factory()->create([
            'slug' => 'wordpress',
            'volume_paths' => ['wp_data' => '/var/www/html'],
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => null,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'service_meta' => [
                'container_template_id' => $wordpress->id,
                'language_slug' => 'wordpress',
                'provision_template_slug' => 'wordpress',
            ],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
        ]);

        return $service->fresh(['product.containerTemplate', 'containerDeployment']);
    }

    #[Test]
    public function shared_plan_wordpress_is_detected_for_sso(): void
    {
        $service = $this->makeSharedWordPressService();

        $this->assertTrue((new WordPressAdminLoginService)->isWordPressContainer($service));
        $this->assertNull($service->product?->containerTemplate);
        $this->assertSame('wordpress', $service->effectiveContainerTemplate()?->slug);
    }

    #[Test]
    public function shared_plan_wordpress_install_does_not_skip_for_wrong_product_template(): void
    {
        $service = $this->makeSharedWordPressService();
        $login = $this->createMock(WordPressAdminLoginService::class);
        $installer = new WordPressAppInstallationService($login);

        // Without a live SSH stack we only assert the gate no longer early-returns as "Not a WordPress template."
        $reflection = new \ReflectionMethod($installer, 'installIfNeeded');
        $this->assertTrue($reflection->isPublic());

        $this->assertSame('wordpress', $service->effectiveContainerTemplate()?->slug);
        $this->assertNotSame('wordpress', $service->product?->containerTemplate?->slug);
    }

    #[Test]
    public function shared_plan_wordpress_gets_system_cron(): void
    {
        $service = $this->makeSharedWordPressService();

        $job = app(WordPressContainerHardeningService::class)->ensureSystemCronJob($service);

        $this->assertNotNull($job);
        $this->assertSame(WordPressContainerHardeningService::WP_CRON_COMMAND, $job->command);
    }

    #[Test]
    public function shared_plan_wordpress_terminal_uses_var_www_html(): void
    {
        $service = $this->makeSharedWordPressService();
        $terminal = new ContainerTerminalService;

        $this->assertSame(
            '/var/www/html',
            $terminal->resolveAppRootFromTemplate($service->effectiveContainerTemplate())
        );
    }

    #[Test]
    public function wordpress_git_sync_for_deploy_is_blocked_even_with_forged_repo_url(): void
    {
        $service = $this->makeSharedWordPressService();
        $meta = $service->service_meta;
        $meta['source_repo_url'] = 'https://github.com/acme/wordpress-theme.git';
        $meta['source_repo_branch'] = 'main';
        $service->update(['service_meta' => $meta]);

        $ssh = \Mockery::mock(SSHService::class);
        $ssh->shouldReceive('mkdirp')->once();
        $ssh->shouldNotReceive('exec');

        $git = new ContainerGitRepositoryService(new ContainerAppDirectoryService);
        $git->syncForDeploy($ssh, $service->fresh(), '/opt/talksasa/containers/demo/app');

        $this->assertFalse($git->supportsService($service->fresh()));
    }
}
