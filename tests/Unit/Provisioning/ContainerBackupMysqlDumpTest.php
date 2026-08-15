<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerBackupService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerBackupMysqlDumpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function wordpress_backups_include_mysql_dump(): void
    {
        $service = $this->makeWordPressService();
        $backup = new ContainerBackupService;

        $this->assertTrue($backup->shouldIncludeMysqlDump($service));
        $this->assertSame('.talksasa-backup/database.sql', $backup->backupDumpRelativePath());
    }

    #[Test]
    public function non_wordpress_backups_skip_mysql_dump(): void
    {
        $template = ContainerTemplate::factory()->create(['slug' => 'php']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create(['product_id' => $product->id]);

        $this->assertFalse((new ContainerBackupService)->shouldIncludeMysqlDump($service->fresh('product.containerTemplate')));
    }

    #[Test]
    public function shared_plan_wordpress_also_includes_mysql_dump(): void
    {
        $wordpress = ContainerTemplate::factory()->create(['slug' => 'wordpress']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => null,
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'service_meta' => [
                'container_template_id' => $wordpress->id,
                'language_slug' => 'wordpress',
            ],
        ]);

        $this->assertTrue((new ContainerBackupService)->shouldIncludeMysqlDump($service->fresh()));
    }

    #[Test]
    public function compose_mysqldump_command_is_safe_and_transactional(): void
    {
        $cmd = (new ContainerBackupService)->buildComposeMysqlDumpCommand(
            '/opt/talksasa/containers/user-1-wordpress',
            'mysql',
            'root',
            'secret"pass',
            'wordpress',
            '/opt/talksasa/containers/user-1-wordpress/.talksasa-backup/database.sql',
        );

        $this->assertStringContainsString('mysqldump', $cmd);
        $this->assertStringContainsString('--single-transaction', $cmd);
        $this->assertStringContainsString('--quick', $cmd);
        $this->assertStringContainsString('--no-tablespaces', $cmd);
        $this->assertStringContainsString("MYSQL_PWD='secret\"pass'", $cmd);
        $this->assertStringContainsString('.talksasa-backup/database.sql', $cmd);
    }

    #[Test]
    public function restore_skips_database_import_when_dump_member_is_missing(): void
    {
        $service = $this->makeWordPressService();
        $deployment = $service->containerDeployment;
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn (string $cmd) => str_contains($cmd, 'database.sql'))
            ->andReturn("no\n");

        $result = (new ContainerBackupService)->restoreMysqlDumpIfPresent(
            $ssh,
            $service,
            $deployment,
            '/opt/talksasa/containers/'.$deployment->container_name,
            'restore-test-1',
        );

        $this->assertNull($result);
    }

    private function makeWordPressService(): Service
    {
        $template = ContainerTemplate::factory()->create(['slug' => 'wordpress']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create(['product_id' => $product->id]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'container_name' => 'user-1-service-'.$service->id.'-wordpress',
            'status' => 'running',
            'env_values' => [
                'MYSQL_ROOT_PASSWORD' => 'rootpass',
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'dbpass',
            ],
        ]);

        return $service->fresh(['product.containerTemplate', 'containerDeployment']);
    }
}
