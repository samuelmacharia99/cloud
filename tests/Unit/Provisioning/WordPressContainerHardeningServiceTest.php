<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerCronJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\Provisioning\WordPressContainerHardeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressContainerHardeningServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function uploads_ini_matches_configured_upload_limit(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $service = new WordPressContainerHardeningService;
        $ini = $service->uploadsIniContents();

        $this->assertStringContainsString('upload_max_filesize = 100M', $ini);
        $this->assertStringContainsString('post_max_size = 100M', $ini);
        $this->assertStringContainsString('memory_limit = 512M', $ini);
        $this->assertStringContainsString(
            '/opt/talksasa/containers/user-1-wordpress/php/uploads.ini:/usr/local/etc/php/conf.d/uploads.ini:ro',
            $service->uploadsIniVolumeMount('user-1-wordpress')
        );
    }

    #[Test]
    public function ensure_system_cron_job_creates_wp_cron_for_wordpress(): void
    {
        $template = ContainerTemplate::factory()->create(['slug' => 'wordpress']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $job = app(WordPressContainerHardeningService::class)->ensureSystemCronJob($service->fresh([
            'product.containerTemplate',
            'containerDeployment',
        ]));

        $this->assertNotNull($job);
        $this->assertSame(WordPressContainerHardeningService::WP_CRON_JOB_NAME, $job->name);
        $this->assertSame(WordPressContainerHardeningService::WP_CRON_COMMAND, $job->command);
        $this->assertSame(WordPressContainerHardeningService::WP_CRON_SCHEDULE, $job->schedule);
        $this->assertTrue($job->enabled);

        $again = app(WordPressContainerHardeningService::class)->ensureSystemCronJob($service->fresh([
            'product.containerTemplate',
            'containerDeployment',
        ]));

        $this->assertSame($job->id, $again?->id);
        $this->assertSame(1, ContainerCronJob::where('service_id', $service->id)->count());
    }

    #[Test]
    public function host_permissions_command_makes_wp_content_writable_for_www_data(): void
    {
        $service = new WordPressContainerHardeningService;
        $cmd = $service->buildHostPermissionsCommand('/opt/talksasa/containers/user-1-wordpress/app');

        $this->assertStringContainsString('chown -R 33:33', $cmd);
        $this->assertStringContainsString('wp-content/uploads', $cmd);
        $this->assertStringContainsString('wp-content/plugins', $cmd);
        $this->assertStringContainsString('chmod -R ug+rwX', $cmd);
        $this->assertStringContainsString('wp-config.php', $cmd);
    }

    #[Test]
    public function in_container_permissions_script_targets_var_www_html(): void
    {
        $script = (new WordPressContainerHardeningService)->inContainerPermissionsScript();

        $this->assertStringContainsString('chown -R www-data:www-data /var/www/html', $script);
        $this->assertStringContainsString('/var/www/html/wp-content/plugins', $script);
        $this->assertStringContainsString('/var/www/html/wp-content/uploads', $script);
    }

    #[Test]
    public function wordpress_work_dir_is_var_www_html(): void
    {
        $stack = new ContainerStackCommandService;
        $this->assertSame('/var/www/html', $stack->resolveWorkDir((object) [
            'slug' => 'wordpress',
            'volume_paths' => ['wp_data' => '/var/www/html'],
        ]));
        $this->assertSame('/var/www/html', $stack->resolveWorkDir((object) [
            'slug' => 'wordpress',
        ]));
    }
}
