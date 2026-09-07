<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressConfigDatabaseCredentialsTest extends TestCase
{
    #[Test]
    public function it_reads_official_image_getenv_docker_defines(): void
    {
        $cfg = <<<'PHP'
<?php
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress') );
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'example') );
define( 'DB_PASSWORD', getenv_docker('WORDPRESS_DB_PASSWORD', 'example') );
define( 'DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'mysql') );
PHP;

        $extracted = app(ContainerDeploymentService::class)
            ->extractWordPressConfigDatabaseDefines($cfg);

        $this->assertTrue($extracted['password_uses_env']);
        $this->assertSame('wordpress', $extracted['DB_NAME']);
        $this->assertSame('example', $extracted['DB_USER']);
        $this->assertSame('example', $extracted['DB_PASSWORD']);
        $this->assertSame('mysql', $extracted['DB_HOST']);
    }

    #[Test]
    public function it_reads_hardcoded_da_import_defines(): void
    {
        $cfg = <<<'PHP'
<?php
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', 'da-old-secret');
define('DB_HOST', 'localhost');
PHP;

        $extracted = app(ContainerDeploymentService::class)
            ->extractWordPressConfigDatabaseDefines($cfg);

        $this->assertFalse($extracted['password_uses_env']);
        $this->assertSame('da-old-secret', $extracted['DB_PASSWORD']);
        $this->assertSame('localhost', $extracted['DB_HOST']);
    }

    #[Test]
    public function repair_rewrites_getenv_docker_and_hardcoded_defines_to_grant_password(): void
    {
        $service = app(ContainerDeploymentService::class);

        $official = <<<'PHP'
<?php
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress') );
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'example') );
define( 'DB_PASSWORD', getenv_docker('WORDPRESS_DB_PASSWORD', 'example') );
define( 'DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'mysql') );
PHP;

        $rewritten = $service->rewriteWordPressConfigDatabaseDefines($official, [
            'DB_NAME' => 'wordpress',
            'DB_USER' => 'wordpress',
            'DB_PASSWORD' => "pa'ss",
            'DB_HOST' => 'mysql',
        ]);

        $this->assertStringContainsString("define('DB_PASSWORD', 'pa\\'ss')", $rewritten);
        $this->assertStringContainsString("define('DB_USER', 'wordpress')", $rewritten);
        $this->assertStringContainsString("define('DB_HOST', 'mysql')", $rewritten);
        $this->assertStringNotContainsString('getenv_docker', $rewritten);

        $imported = $service->rewriteWordPressConfigDatabaseDefines(
            "<?php\ndefine('DB_PASSWORD', 'stale');\n",
            ['DB_PASSWORD' => 'grant-secret']
        );
        $this->assertStringContainsString("define('DB_PASSWORD', 'grant-secret')", $imported);
        $this->assertStringNotContainsString('stale', $imported);
    }
}
