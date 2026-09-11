<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Doctor tested the connection with the credentials the platform stores, which
 * is not the set WordPress uses. The official image writes wp-config.php once,
 * on first boot, and nothing updates it afterwards, so a rotated password or a
 * re-pinned host lands in compose while WordPress keeps what it was born with.
 * The panel then reported a connected database to somebody looking at "Error
 * establishing a database connection".
 */
class WordPressDatabaseConfigAnalyzerTest extends TestCase
{
    #[Test]
    public function a_literal_define_is_what_wordpress_uses(): void
    {
        $credentials = $this->resolve(<<<'PHP_CONFIG'
        <?php
        define('DB_NAME', 's22_db');
        define('DB_USER', 'u246_s22');
        define('DB_PASSWORD', 'old-password');
        define('DB_HOST', 'mysql');
        PHP_CONFIG);

        $this->assertSame('s22_db', $credentials['DB_NAME']);
        $this->assertSame('mysql', $credentials['DB_HOST']);
        $this->assertSame('old-password', $credentials['DB_PASSWORD']);
    }

    #[Test]
    public function a_getenv_define_falls_back_to_the_default_written_into_the_file(): void
    {
        // The container env is empty in this double, so the written default is
        // what PHP resolves, exactly as it would at boot.
        $credentials = $this->resolve(
            "<?php\ndefine('DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'app-mysql'));\n"
        );

        $this->assertSame('app-mysql', $credentials['DB_HOST']);
    }

    #[Test]
    public function a_site_with_no_config_yet_is_not_a_fault(): void
    {
        $this->assertSame([], $this->resolve(''));
    }

    #[Test]
    public function it_names_the_fields_that_disagree_and_never_their_values(): void
    {
        $analyzer = app(WordPressDatabaseConfigAnalyzer::class);

        $differences = $analyzer->differences(
            ['DB_HOST' => 'mysql', 'DB_NAME' => 's22_db', 'DB_USER' => 'u246', 'DB_PASSWORD' => 'old'],
            [
                'WORDPRESS_DB_HOST' => 'user-246-service-22-wordpress-mysql',
                'WORDPRESS_DB_NAME' => 's22_db',
                'WORDPRESS_DB_USER' => 'u246',
                'WORDPRESS_DB_PASSWORD' => 'new',
            ],
        );

        $this->assertSame(['DB_HOST', 'DB_PASSWORD'], $differences);

        $finding = $analyzer->mismatchFinding($differences);
        $this->assertStringNotContainsString('old', $finding['evidence'][1]);
        $this->assertStringContainsString('DB_PASSWORD differs', $finding['evidence'][1]);
    }

    #[Test]
    public function a_port_on_the_host_is_not_a_disagreement(): void
    {
        $differences = app(WordPressDatabaseConfigAnalyzer::class)->differences(
            ['DB_HOST' => 'app-mysql:3306'],
            ['WORDPRESS_DB_HOST' => 'app-mysql'],
        );

        $this->assertSame([], $differences);
    }

    #[Test]
    public function a_field_only_one_side_knows_about_is_not_a_disagreement(): void
    {
        $differences = app(WordPressDatabaseConfigAnalyzer::class)->differences(
            ['DB_HOST' => 'app-mysql'],
            ['WORDPRESS_DB_HOST' => 'app-mysql', 'WORDPRESS_DB_NAME' => 's22_db'],
        );

        $this->assertSame([], $differences);
    }

    #[Test]
    public function an_unreachable_config_is_critical_and_offers_the_existing_repair(): void
    {
        $finding = app(WordPressDatabaseConfigAnalyzer::class)
            ->unreachableFinding(['DB_PASSWORD'], 'Access denied for user', platformProbeOk: true);

        $this->assertSame('wordpress_config_cannot_reach_database', $finding['id']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('sync_database_credentials', $finding['treat_action']);
        $this->assertStringContainsString('while the ones the platform stores work', $finding['summary']);
        $this->assertStringContainsString('Error establishing a database connection', $finding['summary']);
    }

    #[Test]
    public function the_summary_does_not_claim_the_platform_works_when_it_does_not(): void
    {
        $finding = app(WordPressDatabaseConfigAnalyzer::class)
            ->unreachableFinding([], 'Connection refused', platformProbeOk: false);

        $this->assertStringNotContainsString('while the ones the platform stores work', $finding['summary']);
        $this->assertContains('platform credentials also fail', $finding['evidence']);
    }

    /**
     * @return array<string, string>
     */
    private function resolve(string $wpConfig): array
    {
        $deployments = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployments->shouldReceive('readWordPressConfigFile')->andReturn($wpConfig);
        $this->app->instance(ContainerDeploymentService::class, $deployments);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn('');

        return app(WordPressDatabaseConfigAnalyzer::class)->effectiveCredentials($ssh, 'user-246-service-22-wordpress');
    }
}
