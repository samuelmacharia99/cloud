<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorServiceTest extends TestCase
{
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
    public function it_does_not_match_app_key_from_env_dump_alone(): void
    {
        $logs = "APP_KEY=base64:abc\nDB_HOST=db";

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');

        $this->assertNotContains('app_key_missing', array_column($findings, 'id'));
    }

    #[Test]
    public function it_returns_generic_http_500_when_no_signature_matches(): void
    {
        $logs = "[Fri Aug 01 10:00:02] [500]: GET / index.php";

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
        $service = new \App\Models\Service;
        $service->id = 163;
        $service->user_id = 193;
        $service->setRelation('containerDeployment', new \App\Models\ContainerDeployment([
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
    public function it_parses_dotenv_content(): void
    {
        $env = app(ContainerDoctorService::class)->parseEnvFileContent(
            "DB_DATABASE=s163_db\nDATABASE_URL=postgresql://u:p@db:5432/s163_db\n# comment\n"
        );

        $this->assertSame('s163_db', $env['DB_DATABASE']);
        $this->assertSame('postgresql://u:p@db:5432/s163_db', $env['DATABASE_URL']);
    }
}
