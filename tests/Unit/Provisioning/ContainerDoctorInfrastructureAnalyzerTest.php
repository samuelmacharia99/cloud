<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorInfrastructureAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorInfrastructureAnalyzerTest extends TestCase
{
    private function analyzer(): ContainerDoctorInfrastructureAnalyzer
    {
        return new ContainerDoctorInfrastructureAnalyzer;
    }

    #[Test]
    public function it_diagnoses_the_service_24_nginx_fastcgi_crash_loop(): void
    {
        $logs = <<<'LOG'
user-74-service-24-laravel  | /docker-entrypoint.sh: Launching nginx
user-74-service-24-laravel  | 2026/08/25 12:23:28 [emerg] 1#1: open() "/tmp/talksasa-php/fastcgi_params" failed (2: No such file or directory) in /tmp/talksasa-php/nginx.conf:37
user-74-service-24-laravel  | nginx: [emerg] open() "/tmp/talksasa-php/fastcgi_params" failed (2: No such file or directory) in /tmp/talksasa-php/nginx.conf:37
LOG;

        $findings = $this->analyzer()->findings($logs, 'laravel', [
            'restarting' => true,
            'running' => false,
            'image' => 'talksasa/laravel-runtime:8.3-r7',
            'expected_image' => 'talksasa/laravel-runtime:8.3-r8',
            'publishes_port' => false,
            'assigned_port' => 30071,
            'status' => 'Restarting (1) 3 seconds ago',
        ]);

        $ids = array_column($findings, 'id');
        $nginx = collect($findings)->firstWhere('id', 'nginx_boot_failed');

        $this->assertNotNull($nginx);
        $this->assertSame('switch_php_production_runtime', $nginx['treat_action']);
        $this->assertSame('critical', $nginx['severity']);
        $this->assertNotContains('php_builtin_dev_server', $ids);
        $this->assertNotContains('container_crash_loop', $ids);
        $this->assertNotContains('stale_php_runtime_image', $ids);
    }

    #[Test]
    public function it_flags_php_dash_s_when_the_wrapper_fell_back(): void
    {
        $logs = 'Talksasa: nginx/php-fpm unavailable (nginx=missing php-fpm=missing), falling back to php -S (single-threaded)';

        $findings = $this->analyzer()->findings($logs, 'laravel', [
            'process_list' => 'php -S 0.0.0.0:80 /app/server.php',
            'restarting' => false,
            'running' => true,
        ]);

        $finding = collect($findings)->firstWhere('id', 'php_builtin_dev_server');
        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function it_does_not_call_php_dash_s_healthy_when_nginx_is_crashing(): void
    {
        $logs = <<<'LOG'
PHP 8.3.33 Development Server (http://0.0.0.0:80) started
2026/08/25 12:23:28 [emerg] 1#1: open() "/tmp/talksasa-php/fastcgi_params" failed
LOG;

        $ids = array_column($this->analyzer()->findings($logs, 'laravel', [
            'restarting' => true,
        ]), 'id');

        $this->assertContains('nginx_boot_failed', $ids);
        $this->assertNotContains('php_builtin_dev_server', $ids);
    }

    #[Test]
    public function it_flags_a_stale_php_runtime_image_when_the_container_is_up(): void
    {
        $findings = $this->analyzer()->findings('', 'laravel', [
            'image' => 'talksasa/laravel-runtime:8.3-r5',
            'expected_image' => 'talksasa/laravel-runtime:8.3-r8',
            'running' => true,
            'restarting' => false,
        ]);

        $finding = collect($findings)->firstWhere('id', 'stale_php_runtime_image');
        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function it_flags_node_disk_exhaustion(): void
    {
        $findings = $this->analyzer()->findings('write failed: No space left on device', 'nodejs', [
            'disk_percent' => 98,
        ]);

        $finding = collect($findings)->firstWhere('id', 'node_disk_exhausted');
        $this->assertNotNull($finding);
        $this->assertNull($finding['treat_action']);
    }

    #[Test]
    public function it_flags_mysql_connection_refused(): void
    {
        $logs = 'SQLSTATE[HY000] [2002] Connection refused';
        $finding = collect($this->analyzer()->findings($logs, 'laravel'))->firstWhere('id', 'mysql_connection_refused');

        $this->assertNotNull($finding);
        $this->assertSame('sync_database_credentials', $finding['treat_action']);
    }

    #[Test]
    public function it_flags_mysql_unix_socket_not_repair_credentials(): void
    {
        $logs = <<<'LOG'
🚀 Server running on http://localhost:3000
❌ Database connection failed:  Error: connect ENOENT /var/lib/mysql/mysql.sock
    at PipeConnectWrap.afterConnect [as oncomplete] (node:net:1611:16)
LOG;

        $findings = $this->analyzer()->findings($logs, 'nodejs');
        $ids = array_column($findings, 'id');
        $finding = collect($findings)->firstWhere('id', 'mysql_unix_socket_missing');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertNotContains('mysql_connection_refused', $ids);
    }

    #[Test]
    public function it_flags_missing_vendor_autoload(): void
    {
        $logs = 'Fatal error: Failed opening required \'/app/vendor/autoload.php\' (include_path=\'.:/usr/local/lib/php\')';
        $finding = collect($this->analyzer()->findings($logs, 'laravel'))->firstWhere('id', 'missing_vendor_autoload');

        $this->assertNotNull($finding);
        $this->assertNull($finding['treat_action']);
    }

    #[Test]
    public function it_flags_oom_over_a_generic_crash_loop(): void
    {
        $ids = array_column($this->analyzer()->findings('kernel: Out of memory: Killed process 12', 'nodejs', [
            'oom' => true,
            'restarting' => true,
        ]), 'id');

        $this->assertContains('oom_killed', $ids);
        $this->assertNotContains('container_crash_loop', $ids);
    }

    #[Test]
    public function it_flags_a_generic_crash_loop_when_no_boot_signature_matches(): void
    {
        $finding = collect($this->analyzer()->findings('Listening...', 'nodejs', [
            'restarting' => true,
        ]))->firstWhere('id', 'container_crash_loop');

        $this->assertNotNull($finding);
        $this->assertSame('recreate_application', $finding['treat_action']);
    }

    #[Test]
    public function it_flags_nginx_unable_to_reach_php_fpm(): void
    {
        $logs = '2026/08/25 12:40:01 [error] 12#12: *1 connect() failed (111: Connection refused) while connecting to upstream, upstream: "unix:/tmp/talksasa-php/php-fpm.sock"';
        $finding = collect($this->analyzer()->findings($logs, 'laravel'))->firstWhere('id', 'php_fpm_sock_missing');

        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function it_flags_php_fpm_worker_exhaustion(): void
    {
        $logs = 'WARNING: [pool www] server reached pm.max_children setting (3), consider raising it';
        $finding = collect($this->analyzer()->findings($logs, 'laravel'))->firstWhere('id', 'php_fpm_max_children');

        $this->assertNotNull($finding);
        $this->assertSame('tune_request_concurrency', $finding['treat_action']);
    }

    #[Test]
    public function it_flags_nginx_directory_index_on_app_root(): void
    {
        $logs = '2026/08/26 14:41:47 [error] 18#18: *1 directory index of "/app/" is forbidden, client: 10.201.0.1, request: "GET / HTTP/1.1"';
        $finding = collect($this->analyzer()->findings($logs, 'laravel'))->firstWhere('id', 'laravel_docroot_not_public');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('Point nginx at public/', $finding['treat_label']);
    }
}
