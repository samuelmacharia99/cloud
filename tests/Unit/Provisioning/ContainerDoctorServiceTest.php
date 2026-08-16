<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerDoctorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorServiceTest extends TestCase
{
    #[Test]
    public function it_detects_vite_missing_from_production_start(): void
    {
        $logs = <<<'LOG'
> react-example@0.0.0 start
> NODE_OPTIONS='--no-warnings' tsx server.ts
Error [ERR_MODULE_NOT_FOUND]: Cannot find package 'vite' imported from /app/server.ts
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');
        $finding = collect($findings)->firstWhere('id', 'vite_missing_in_production');

        $this->assertNotNull($finding);
        $this->assertSame('fix_vite_production_runtime', $finding['treat_action']);
        $this->assertSame('critical', $finding['severity']);
    }

    #[Test]
    public function it_detects_postgres_password_authentication_failures(): void
    {
        $logs = <<<'LOG'
[Fri Aug 01 10:00:01] [200]: GET /health
SQLSTATE[08006] [7] connection to server at "db" failed: FATAL: password authentication failed for user "u193_s163"
[Fri Aug 01 10:00:02] [500]: GET /
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $ids = array_column($findings, 'id');

        $this->assertContains('postgres_password_auth_failed', $ids);
        $finding = collect($findings)->firstWhere('id', 'postgres_password_auth_failed');
        $this->assertSame('sync_database_credentials', $finding['treat_action']);
        $this->assertSame('critical', $finding['severity']);
    }

    #[Test]
    public function it_detects_missing_postgres_database(): void
    {
        $logs = 'FATAL: database "u193_s163" does not exist';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');

        $this->assertContains('postgres_database_missing', array_column($findings, 'id'));
    }

    #[Test]
    public function it_detects_missing_pdo_pgsql(): void
    {
        $logs = 'PDOException: could not find driver';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');

        $this->assertContains('missing_pdo_pgsql', array_column($findings, 'id'));
    }

    #[Test]
    public function it_detects_node_not_found(): void
    {
        $logs = 'sh: 1: eval: node: not found';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $finding = collect($findings)->firstWhere('id', 'node_not_found');

        $this->assertNotNull($finding);
        $this->assertSame('ensure_node', $finding['treat_action']);
    }

    #[Test]
    public function it_detects_missing_cache_locks_table(): void
    {
        $logs = 'ERROR:  relation "cache_locks" does not exist at character 13';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $finding = collect($findings)->firstWhere('id', 'missing_cache_locks_table');

        $this->assertNotNull($finding);
        $this->assertSame('use_file_cache', $finding['treat_action']);
    }

    #[Test]
    public function it_does_not_match_app_key_from_env_dump_alone(): void
    {
        $logs = "APP_KEY=base64:abc\nDB_HOST=db";

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');

        $this->assertNotContains('app_key_missing', array_column($findings, 'id'));
    }

    #[Test]
    public function it_returns_generic_http_500_when_no_signature_matches(): void
    {
        $logs = '[Fri Aug 01 10:00:02] [500]: GET / index.php';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');

        $this->assertSame(['http_500_generic'], array_column($findings, 'id'));
    }

    #[Test]
    public function it_skips_stack_specific_rules_for_unrelated_stacks(): void
    {
        $logs = 'ext-gd is missing from your system';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');

        $this->assertNotContains('missing_ext_gd', array_column($findings, 'id'));
    }

    #[Test]
    public function it_downgrades_missing_database_finding_when_env_already_fixed(): void
    {
        $service = new Service;
        $service->id = 163;
        $service->user_id = 193;
        $service->setRelation('containerDeployment', new ContainerDeployment([
            'env_values' => [
                'DB_DATABASE' => 's163_db',
                'DB_USERNAME' => 'u193_s163',
                'DB_PASSWORD' => 'secret',
                'POSTGRES_PASSWORD' => 'secret',
                'DATABASE_URL' => 'postgresql://u193_s163:secret@db:5432/s163_db',
            ],
        ]));

        $findings = [[
            'id' => 'postgres_database_missing',
            'severity' => 'critical',
            'title' => 'PostgreSQL database does not exist',
            'summary' => 'Missing',
            'evidence' => ['FATAL: database "u193_s163" does not exist'],
            'treat_action' => 'sync_database_credentials',
            'treat_label' => 'Create/sync database',
            'manual_steps' => [],
        ]];

        $annotated = app(ContainerDoctorService::class)->annotateFindingsWithLiveStatus($service, $findings);

        $this->assertSame('info', $annotated[0]['severity']);
        $this->assertTrue($annotated[0]['stale']);
        $this->assertStringContainsString('current env looks fixed', $annotated[0]['title']);
    }

    #[Test]
    public function it_prefers_database_url_credentials_for_runtime_probe(): void
    {
        $probe = app(ContainerDoctorService::class)->envForRuntimeDatabaseProbe([
            'DB_DATABASE' => 's163_db',
            'DB_USERNAME' => 'u193_s163',
            'DB_PASSWORD' => 'panel-password',
            'DATABASE_URL' => 'postgresql://u193_s163:url-password@db:5432/s163_db',
        ], 'postgresql');

        $this->assertSame('url-password', $probe['DB_PASSWORD']);
        $this->assertSame('s163_db', $probe['DB_DATABASE']);
    }

    #[Test]
    public function it_drops_stale_log_findings_when_live_critical_exists(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'postgres_database_missing',
                'severity' => 'info',
                'stale' => true,
                'title' => 'Older logs',
            ]],
            ['findings' => [[
                'id' => 'live_db_connection_failed',
                'severity' => 'critical',
                'title' => 'Live auth failed',
            ]], 'checks' => ['db_ok' => false]]
        );

        $ids = array_column($merged, 'id');
        $this->assertNotContains('postgres_database_missing', $ids);
        $this->assertContains('live_db_connection_failed', $ids);
    }

    #[Test]
    public function it_drops_resolved_db_log_findings_when_live_db_is_ok(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'postgres_password_auth_failed',
                'severity' => 'critical',
                'title' => 'Old auth failure',
            ]],
            [
                'findings' => [[
                    'id' => 'live_http_5xx',
                    'severity' => 'critical',
                    'title' => 'HTTP 500',
                ]],
                'checks' => ['db_ok' => true, 'http_status' => 500, 'table_count' => 12],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertNotContains('postgres_password_auth_failed', $ids);
        $this->assertContains('live_http_5xx', $ids);
    }

    #[Test]
    public function it_suppresses_stale_log_findings_when_the_upstream_is_unreachable(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'postgres_password_auth_failed',
                'severity' => 'critical',
                'title' => 'Old auth failure',
            ]],
            [
                'findings' => [[
                    'id' => 'live_upstream_unreachable',
                    'severity' => 'critical',
                    'title' => 'Proxy cannot reach the app',
                ]],
                'checks' => ['http_status' => 502, 'upstream_reachable' => false],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertNotContains('postgres_password_auth_failed', $ids);
        $this->assertContains('live_upstream_unreachable', $ids);
    }

    #[Test]
    public function unreachable_upstream_failure_message_names_the_real_cause(): void
    {
        $message = $this->callPrivate('upstreamFailureMessage', [[
            'assigned_port' => 31123,
            'local_status' => 0,
            'reachable' => false,
            'containers' => [],
            'stopped' => ['app_s163 (Exited (1) 4 seconds ago)'],
            'publishes_port' => false,
            'crash_logs' => ['app_s163: Error: Cannot find module /app/server.js'],
        ]]);

        $this->assertStringContainsString('127.0.0.1:31123', $message);
        $this->assertStringContainsString('Exited (1)', $message);
        $this->assertStringContainsString('no container publishes host port 31123', $message);
        $this->assertStringContainsString('Cannot find module', $message);
    }

    #[Test]
    public function unreachable_upstream_evidence_separates_public_and_local_probes(): void
    {
        $evidence = $this->callPrivate('upstreamEvidence', [
            [
                'assigned_port' => 31123,
                'local_status' => null,
                'reachable' => false,
                'containers' => [],
                'stopped' => [],
                'publishes_port' => true,
                'crash_logs' => [],
            ],
            502,
            'https://gateway.example.test',
        ]);

        $this->assertStringContainsString('public URL: HTTP 502', $evidence[0]);
        $this->assertStringContainsString('gateway.example.test', $evidence[0]);
        $this->assertStringContainsString('127.0.0.1:31123', $evidence[1]);
        $this->assertStringContainsString('connection refused', $evidence[1]);
    }

    #[Test]
    public function a_running_install_or_build_is_reported_as_progress_not_failure(): void
    {
        $message = $this->callPrivate('upstreamFailureMessage', [[
            'assigned_port' => 30022,
            'local_status' => 0,
            'reachable' => false,
            'containers' => [],
            'stopped' => [],
            'publishes_port' => true,
            'crash_logs' => [],
            'bootstrapping' => 'npm warn idealTree Removing dependencies.vite in favor of devDependencies.vite',
        ]]);

        $this->assertStringContainsString('still installing dependencies and building', $message);
        $this->assertStringContainsString('idealTree', $message);
        $this->assertStringNotContainsString('not answering', $message);
    }

    #[Test]
    public function it_detects_vite_blocking_the_bound_domain(): void
    {
        $findings = app(ContainerDoctorService::class)->analyzeLogs(
            'Blocked request. This host ("gateway.example.test") is not allowed.',
            'nodejs'
        );

        $finding = collect($findings)->firstWhere('id', 'vite_host_not_allowed');

        $this->assertNotNull($finding);
        $this->assertSame('fix_vite_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function missing_image_editor_explains_the_document_icon_grid(): void
    {
        $findings = $this->callPrivate('wordPressMediaFindings', [
            [
                'gd' => false,
                'imagick' => false,
                'editor' => false,
                'home' => 'https://shop.example.test',
                'siteurl' => 'https://shop.example.test',
                'basedir' => '/var/www/html/wp-content/uploads',
                'images' => 32,
                'missing_sizes' => 32,
                'latest_file' => '2026/08/hero.jpg',
                'latest_file_exists' => true,
            ],
            'https://shop.example.test',
        ]);

        $finding = collect($findings)->firstWhere('id', 'live_wordpress_image_editor_missing');

        $this->assertNotNull($finding);
        $this->assertSame('fix_wordpress_media_processing', $finding['treat_action']);
        $this->assertContains('GD: missing', $finding['evidence']);
        // The thumbnail finding would be redundant while there is no image editor at all.
        $this->assertNull(collect($findings)->firstWhere('id', 'live_wordpress_missing_thumbnails'));
    }

    #[Test]
    public function attachments_without_sizes_are_offered_a_thumbnail_rebuild(): void
    {
        $findings = $this->callPrivate('wordPressMediaFindings', [
            [
                'gd' => true,
                'imagick' => false,
                'editor' => true,
                'home' => 'https://shop.example.test',
                'siteurl' => 'https://shop.example.test',
                'basedir' => '/var/www/html/wp-content/uploads',
                'images' => 32,
                'missing_sizes' => 4,
                'latest_file' => '2026/08/hero.jpg',
                'latest_file_exists' => true,
            ],
            'https://shop.example.test',
        ]);

        $finding = collect($findings)->firstWhere('id', 'live_wordpress_missing_thumbnails');

        $this->assertNotNull($finding);
        $this->assertSame('regenerate_wordpress_thumbnails', $finding['treat_action']);
        $this->assertStringContainsString('4 image', $finding['title']);
    }

    #[Test]
    public function a_stale_site_url_and_missing_files_are_reported_separately(): void
    {
        $findings = $this->callPrivate('wordPressMediaFindings', [
            [
                'gd' => true,
                'imagick' => false,
                'editor' => true,
                'home' => 'http://31.97.60.10:30012',
                'siteurl' => 'http://31.97.60.10:30012',
                'basedir' => '/var/www/html/wp-content/uploads',
                'images' => 8,
                'missing_sizes' => 0,
                'latest_file' => '2026/08/hero.jpg',
                'latest_file_exists' => false,
            ],
            'https://shop.example.test',
        ]);

        $ids = array_column($findings, 'id');

        $this->assertContains('live_wordpress_site_url_mismatch', $ids);
        $this->assertContains('live_wordpress_media_files_missing', $ids);
        $this->assertSame(
            'fix_wordpress_site_url',
            collect($findings)->firstWhere('id', 'live_wordpress_site_url_mismatch')['treat_action']
        );
    }

    #[Test]
    public function a_healthy_media_library_produces_no_findings(): void
    {
        $findings = $this->callPrivate('wordPressMediaFindings', [
            [
                'gd' => true,
                'imagick' => true,
                'editor' => true,
                'home' => 'https://shop.example.test/',
                'siteurl' => 'https://shop.example.test',
                'basedir' => '/var/www/html/wp-content/uploads',
                'images' => 12,
                'missing_sizes' => 0,
                'latest_file' => '2026/08/hero.jpg',
                'latest_file_exists' => true,
            ],
            'https://shop.example.test',
        ]);

        $this->assertSame([], $findings);
    }

    private function callPrivate(string $method, array $arguments): mixed
    {
        $service = app(ContainerDoctorService::class);
        $reflection = new \ReflectionMethod($service, $method);

        return $reflection->invokeArgs($service, $arguments);
    }

    #[Test]
    public function it_parses_dotenv_content(): void
    {
        $env = app(ContainerDoctorService::class)->parseEnvFileContent(
            "DB_DATABASE=s163_db\nDATABASE_URL=postgresql://u:p@db:5432/s163_db\n# comment\n"
        );

        $this->assertSame('s163_db', $env['DB_DATABASE']);
        $this->assertSame('postgresql://u:p@db:5432/s163_db', $env['DATABASE_URL']);
    }
}
