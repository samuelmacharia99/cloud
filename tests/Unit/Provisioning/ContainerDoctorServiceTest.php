<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Services\Provisioning\ContainerAppDirectoryService;
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
    public function it_detects_npm_workspace_protocol_failures(): void
    {
        $logs = <<<'LOG'
npm warn config production Use `--omit=dev` instead.
npm error code EUNSUPPORTEDPROTOCOL
npm error Unsupported URL Type "workspace:": workspace:*
sh: next: Permission denied
LOG;

        $doctor = app(ContainerDoctorService::class);
        $finding = collect($doctor->analyzeLogs($logs, 'nodejs'))->firstWhere('id', 'npm_workspace_protocol');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('Start the Node app', $finding['treat_label']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertTrue($doctor->bootstrapLogsLookFatal($logs));
        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($logs));
    }

    #[Test]
    public function it_detects_package_manager_field_mismatch(): void
    {
        $logs = <<<'LOG'
user-493-service-454-nodejs  | This project is configured to use npm because /app/package.json has a "packageManager" field
LOG;

        $doctor = app(ContainerDoctorService::class);
        $finding = collect($doctor->analyzeLogs($logs, 'nodejs'))->firstWhere('id', 'node_package_manager_mismatch');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('Start the Node app', $finding['treat_label']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertTrue($doctor->bootstrapLogsLookFatal($logs));
        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($logs));
    }

    #[Test]
    public function package_manager_mismatch_replaces_crash_loop_recreate(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'node_package_manager_mismatch',
                'severity' => 'critical',
                'title' => 'Wrong package manager for this Node app',
                'treat_action' => 'restart_application',
            ]],
            [
                'findings' => [
                    [
                        'id' => 'live_bootstrap_in_progress',
                        'severity' => 'warning',
                        'title' => 'Live check: the app is still installing and building',
                    ],
                    [
                        'id' => 'container_crash_loop',
                        'severity' => 'critical',
                        'title' => 'Application container is crash-looping',
                        'treat_action' => 'recreate_application',
                    ],
                    [
                        'id' => 'live_upstream_unreachable',
                        'severity' => 'critical',
                        'title' => 'App port is not answering',
                    ],
                ],
                'checks' => ['http_status' => 502, 'db_ok' => null],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('node_package_manager_mismatch', $ids);
        $this->assertNotContains('live_bootstrap_in_progress', $ids);
        $this->assertNotContains('container_crash_loop', $ids);
        $this->assertNotContains('live_upstream_unreachable', $ids);
    }

    #[Test]
    public function npm_deprecation_warnings_are_not_bootstrap_progress(): void
    {
        $doctor = app(ContainerDoctorService::class);
        $deprecated = <<<'LOG'
user-493-service-454-nodejs: npm warn deprecated glob@7.2.3: Old versions of glob are not supported
user-493-service-454-nodejs: npm warn deprecated uuid@8.3.2: uuid@10 and below is no longer supported
LOG;

        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($deprecated));
        $this->assertFalse($doctor->bootstrapLogsLookFatal($deprecated));
    }

    #[Test]
    public function npm_config_warning_is_not_bootstrap_progress(): void
    {
        $doctor = app(ContainerDoctorService::class);

        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress(
            'user-493-service-454-nodejs | npm warn config production Use `--omit=dev` instead.'
        ));
    }

    #[Test]
    public function completed_npm_install_plus_package_manager_loop_is_fatal_not_progress(): void
    {
        $recent = <<<'LOG'
user-493-service-454-nodejs: npm warn deprecated glob@7.2.3: Old versions of glob are not supported
user-493-service-454-nodejs: added 1059 packages in 16s
LOG;
        $compose = <<<'LOG'
user-493-service-454-nodejs-db  | FATAL:  role "postgres" does not exist
user-493-service-454-nodejs     | This project is configured to use npm because /app/package.json has a "packageManager" field
user-493-service-454-nodejs     | This project is configured to use npm because /app/package.json has a "packageManager" field
LOG;

        $doctor = app(ContainerDoctorService::class);
        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($recent));
        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($recent, $compose));
        $this->assertTrue($doctor->bootstrapLogsLookFatal($compose));

        $findings = $doctor->analyzeLogs($recent."\n".$compose, 'nodejs');
        $ids = array_column($findings, 'id');
        $this->assertContains('node_package_manager_mismatch', $ids);
        $this->assertContains('postgres_role_missing', $ids);
    }

    #[Test]
    public function node_20_engine_mismatch_is_fatal_and_offers_node_22_upgrade(): void
    {
        $logs = <<<'LOG'
npm warn EBADENGINE Unsupported engine {
npm warn EBADENGINE   package: 'sameplan@0.1.0',
npm warn EBADENGINE   required: { node: '>=22' },
npm warn EBADENGINE   current: { node: 'v20.20.2', npm: '10.8.2' }
npm warn EBADENGINE }
added 1059 packages in 20s
LOG;

        $doctor = app(ContainerDoctorService::class);
        $finding = collect($doctor->analyzeLogs($logs, 'nodejs'))
            ->firstWhere('id', 'node_runtime_too_old');

        $this->assertNotNull($finding);
        $this->assertSame('upgrade_node_runtime', $finding['treat_action']);
        $this->assertSame('Upgrade to Node 22', $finding['treat_label']);
        $this->assertTrue($doctor->bootstrapLogsLookFatal($logs));
        $this->assertNull($doctor->recentLogsIndicateBootstrapProgress($logs));
    }

    #[Test]
    public function node_22_upgrade_works_when_the_persisted_template_versions_are_stale(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'nodejs',
            'docker_image' => 'node:20-alpine',
            'versions' => ['18-alpine', '20-alpine'],
        ]);

        $selection = app(ContainerDoctorService::class)
            ->node22RuntimeSelection($template, 'image: node:20-alpine');

        $this->assertSame('22-alpine', $selection['version']);
        $this->assertSame('node:22-alpine', $selection['image']);
        $this->assertContains('22-slim', ContainerTemplate::nodeRuntimeVersions());
        $this->assertContains('22', ContainerTemplate::nodeRuntimeVersions());
    }

    #[Test]
    public function old_node_20_engine_logs_are_removed_after_the_live_container_runs_node_22(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'node_runtime_too_old',
                'severity' => 'critical',
                'title' => 'This app requires Node 22',
            ]],
            [
                'findings' => [[
                    'id' => 'live_upstream_unreachable',
                    'severity' => 'critical',
                    'title' => 'App port is not answering',
                ]],
                'checks' => [
                    'http_status' => 502,
                    'container_image' => 'node:22-alpine',
                ],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertNotContains('node_runtime_too_old', $ids);
        $this->assertContains('live_upstream_unreachable', $ids);
    }

    #[Test]
    public function workspace_protocol_finding_replaces_false_bootstrap_progress(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'npm_workspace_protocol',
                'severity' => 'critical',
                'title' => 'npm cannot install a pnpm/yarn workspace',
                'treat_action' => 'restart_application',
            ]],
            [
                'findings' => [
                    [
                        'id' => 'live_bootstrap_in_progress',
                        'severity' => 'warning',
                        'title' => 'Live check: the app is still installing and building',
                    ],
                    [
                        'id' => 'container_crash_loop',
                        'severity' => 'critical',
                        'title' => 'Application container is crash-looping',
                    ],
                ],
                'checks' => ['http_status' => 502, 'db_ok' => null],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('npm_workspace_protocol', $ids);
        $this->assertNotContains('live_bootstrap_in_progress', $ids);
        $this->assertNotContains('container_crash_loop', $ids);
    }

    #[Test]
    public function it_detects_the_empty_node_placeholder_from_bound_url_text(): void
    {
        $logs = <<<'LOG'
user-231-service-351-nodejs  | Talksasa: add your Node.js app to /app
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');
        $finding = collect($findings)->firstWhere('id', 'node_placeholder_runtime');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('Start the Node app', $finding['treat_label']);
        $this->assertSame('critical', $finding['severity']);
    }

    #[Test]
    public function it_detects_vite_missing_from_bundled_dist_server(): void
    {
        $logs = <<<'LOG'
> react-example@0.0.0 start
> node dist/server.cjs

Error: Cannot find module 'vite'
Require stack:
- /app/dist/server.cjs
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');
        $finding = collect($findings)->firstWhere('id', 'vite_missing_in_production');

        $this->assertNotNull($finding);
        $this->assertSame('fix_vite_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function it_detects_directadmin_mysql_unix_socket_from_nodejs_logs(): void
    {
        $logs = <<<'LOG'
user-67-service-179-nodejs  | npm warn config production Use `--omit=dev` instead.
user-67-service-179-nodejs  | 12 vulnerabilities (2 moderate, 9 high, 1 critical)
user-67-service-179-nodejs  | To address all issues (including breaking changes), run:
user-67-service-179-nodejs  |   npm audit fix --force
user-67-service-179-nodejs  | > sigtuna@1.0.0 start
user-67-service-179-nodejs  | > node app.js
user-67-service-179-nodejs  | 🚀 Server running on http://localhost:3000
user-67-service-179-nodejs  | ❌ Database connection failed:  Error: connect ENOENT /var/lib/mysql/mysql.sock
user-67-service-179-nodejs  |     at PipeConnectWrap.afterConnect [as oncomplete] (node:net:1611:16)
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');
        $ids = array_column($findings, 'id');
        $finding = collect($findings)->firstWhere('id', 'mysql_unix_socket_missing');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertNotContains('mysql_connection_refused', $ids);
        $this->assertNotContains('mysql_access_denied', $ids);
        $this->assertNotContains('vite_missing_in_production', $ids);
    }

    #[Test]
    public function npm_audit_and_localhost_console_log_are_not_findings(): void
    {
        $logs = <<<'LOG'
npm warn config production Use `--omit=dev` instead.
12 vulnerabilities (2 moderate, 9 high, 1 critical)
npm notice New major version of npm available! 10.8.2 -> 12.0.2
> sigtuna@1.0.0 start
> node app.js
🚀 Server running on http://localhost:3000
LOG;

        $this->assertSame([], app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs'));
    }

    #[Test]
    public function mysql_unix_socket_finding_stays_when_doctor_pdo_is_ok(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'mysql_unix_socket_missing',
                'severity' => 'critical',
                'title' => 'App is using a MySQL unix socket that does not exist in Docker',
                'treat_action' => 'restart_application',
            ]],
            [
                'findings' => [],
                'checks' => ['http_status' => 200, 'db_ok' => true, 'table_count' => 12],
            ]
        );

        $this->assertContains('mysql_unix_socket_missing', array_column($merged, 'id'));
    }

    #[Test]
    public function it_flags_preview_runtime_that_hides_custom_api_routes(): void
    {
        $compose = "services:\n  app:\n    command:\n    - sh\n    - -lc\n"
            ."    - cd /app && exec npx vite preview --host 0.0.0.0 --port \${PORT:-3000} --strictPort\n";
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'vite build',
                'start' => 'node dist/server.cjs',
            ],
            'devDependencies' => ['vite' => '^6.0.0'],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(
            app(ContainerDoctorService::class)->spaRuntimeHidesApiRoutes($compose, $packageJson)
        );
    }

    #[Test]
    public function it_does_not_flag_preview_runtime_for_spa_only_apps(): void
    {
        $compose = "services:\n  app:\n    command:\n    - sh\n    - -lc\n"
            ."    - cd /app && exec npx vite preview --host 0.0.0.0 --port \${PORT:-3000} --strictPort\n";
        $doctor = app(ContainerDoctorService::class);

        $bareCli = json_encode([
            'scripts' => ['build' => 'vite build', 'start' => 'vite --host 0.0.0.0'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ], JSON_THROW_ON_ERROR);
        $alreadyPreview = json_encode([
            'scripts' => ['build' => 'vite build', 'start' => 'vite preview'],
            'devDependencies' => ['vite' => '^5.0.0'],
        ], JSON_THROW_ON_ERROR);

        $this->assertFalse($doctor->spaRuntimeHidesApiRoutes($compose, $bareCli));
        $this->assertFalse($doctor->spaRuntimeHidesApiRoutes($compose, $alreadyPreview));
        $this->assertFalse($doctor->spaRuntimeHidesApiRoutes($compose, null));
    }

    #[Test]
    public function it_does_not_flag_api_mismatch_when_runtime_keeps_the_app_server(): void
    {
        $compose = "services:\n  app:\n    command:\n    - sh\n    - -lc\n"
            ."    - cd /app && export PORT=\${PORT:-3000} && exec npm start\n";
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'vite build',
                'start' => 'node dist/server.cjs',
            ],
            'devDependencies' => ['vite' => '^6.0.0'],
        ], JSON_THROW_ON_ERROR);

        $this->assertFalse(
            app(ContainerDoctorService::class)->spaRuntimeHidesApiRoutes($compose, $packageJson)
        );
    }

    #[Test]
    public function it_detects_php_builtin_development_server_from_logs(): void
    {
        $logs = <<<'LOG'
user-74-service-24-laravel  | PHP 8.3.33 Development Server (http://0.0.0.0:80) started
user-74-service-24-laravel  | [Tue Aug 25 11:29:35 2026] 10.201.0.1:53570 [200]: GET /login
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $finding = collect($findings)->firstWhere('id', 'php_builtin_dev_server');

        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
        $this->assertSame('warning', $finding['severity']);
    }

    #[Test]
    public function it_does_not_flag_php_dev_server_on_unrelated_stacks(): void
    {
        $logs = 'PHP 8.3.33 Development Server (http://0.0.0.0:80) started';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');

        $this->assertNotContains('php_builtin_dev_server', array_column($findings, 'id'));
    }

    #[Test]
    public function it_detects_php_minus_s_in_compose_yaml(): void
    {
        $doctor = app(ContainerDoctorService::class);
        $compose = <<<'YAML'
services:
  app:
    command:
      - php
      - '-S'
      - 0.0.0.0:8000
      - '-t'
      - /app/public
YAML;

        $this->assertTrue($doctor->composeUsesPhpBuiltinDevServer($compose));
        $this->assertFalse($doctor->composeUsesPhpBuiltinDevServer(
            "command:\n      - talksasa-php-server\n      - '8000'\n      - /app/public\n"
        ));
        $this->assertTrue($doctor->commandLooksLikePhpBuiltinDevServer('php -S 0.0.0.0:80 -t /app/public'));
        $this->assertFalse($doctor->commandLooksLikePhpBuiltinDevServer('nginx: master process nginx -c /tmp/talksasa-php/nginx.conf'));
        $this->assertTrue($doctor->processListUsesPhpBuiltinDevServer("php-fpm: master\nphp -S 0.0.0.0:80 -t /app/public /app/public/index.php"));
        $this->assertTrue($doctor->processListUsesPhpFpm('nginx: master process nginx -c /tmp/talksasa-php/nginx.conf'));
    }

    #[Test]
    public function it_detects_nginx_directory_index_forbidden_on_app_root(): void
    {
        $logs = <<<'LOG'
user-85-service-27-laravel  | Talksasa: starting nginx + php-fpm on :80 (docroot /app, 6 PHP workers)
user-85-service-27-laravel  | 2026/08/26 14:41:47 [error] 18#18: *1 directory index of "/app/" is forbidden, client: 10.201.0.1, server: , request: "GET / HTTP/1.1", host: "tajmaal.co.ke"
LOG;

        $finding = collect(app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel'))
            ->firstWhere('id', 'laravel_docroot_not_public');

        $this->assertNotNull($finding);
        $this->assertSame('restart_application', $finding['treat_action']);
        $this->assertSame('Point nginx at public/', $finding['treat_label']);
    }

    #[Test]
    public function it_detects_nginx_directory_index_forbidden_on_static_html_root(): void
    {
        $logs = <<<'LOG'
user-483-service-426-static-site  | 2026/09/07 07:18:02 [error] 31#31: *26 directory index of "/usr/share/nginx/html/" is forbidden, client: 10.201.0.1, server: localhost, request: "GET / HTTP/1.1", host: "roadtrip.digiworldmediasln.com"
user-483-service-426-static-site  | 10.201.0.1 - - [07/Sep/2026:07:21:23 +0000] "GET /.env HTTP/1.1" 200 3181 "-" "Mozilla/5.0"
LOG;

        $finding = collect(app(ContainerDoctorService::class)->analyzeLogs($logs, 'static-site'))
            ->firstWhere('id', 'static_site_empty_docroot');

        $this->assertNotNull($finding);
        $this->assertSame('fix_static_site_docroot', $finding['treat_action']);
        $this->assertSame('Fix static web root', $finding['treat_label']);
    }

    #[Test]
    public function it_treats_the_talksasa_welcome_page_as_a_static_site_placeholder(): void
    {
        $this->assertTrue(app(ContainerAppDirectoryService::class)->htmlLooksLikePlaceholder(
            '<title>Welcome to Talksasa Cloud</title><h1>Welcome to Talksasa Cloud</h1>'
        ));
    }

    #[Test]
    public function php_on_static_nginx_finding_replaces_empty_docroot_from_logs(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'static_site_empty_docroot',
                'severity' => 'critical',
                'title' => 'nginx has no index.html at the web root',
                'treat_action' => 'fix_static_site_docroot',
            ]],
            [
                'findings' => [[
                    'id' => 'static_site_php_on_nginx',
                    'severity' => 'critical',
                    'title' => 'This is a PHP app on the static nginx image',
                    'treat_action' => 'switch_php_production_runtime',
                ]],
                'checks' => ['http_status' => 403, 'db_ok' => null],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('static_site_php_on_nginx', $ids);
        $this->assertNotContains('static_site_empty_docroot', $ids);
        $this->assertSame('switch_php_production_runtime', $merged[0]['treat_action']);
    }

    #[Test]
    public function missing_mysql_sidecar_finding_replaces_credential_drift(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [],
            [
                'findings' => [
                    [
                        'id' => 'live_env_credential_drift',
                        'severity' => 'critical',
                        'title' => 'Live .env database credentials are inconsistent',
                        'treat_action' => 'sync_database_credentials',
                    ],
                    [
                        'id' => 'missing_database_sidecar',
                        'severity' => 'critical',
                        'title' => 'This PHP app has no MySQL sidecar',
                        'treat_action' => 'sync_database_credentials',
                    ],
                ],
                'checks' => ['http_status' => 500, 'db_ok' => false],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('missing_database_sidecar', $ids);
        $this->assertNotContains('live_env_credential_drift', $ids);
    }

    #[Test]
    public function production_php_server_does_not_route_static_files_through_index_php(): void
    {
        $script = (string) file_get_contents(base_path('deploy/docker/runtimes/common/php-production-server.sh'));
        $dockerfile = (string) file_get_contents(base_path('deploy/docker/runtimes/laravel/Dockerfile'));

        $this->assertStringContainsString('/usr/sbin/nginx', $script);
        $this->assertStringContainsString('/usr/local/sbin/php-fpm', $script);
        $this->assertStringContainsString('server.php', $script);
        $this->assertDoesNotMatchRegularExpression('/php -S .*index\.php/', $script);
        $this->assertStringContainsString('/usr/sbin', $dockerfile);
        $this->assertStringNotContainsString('include fastcgi_params;', $script);
        $this->assertStringContainsString('include $TMP/fastcgi_params;', $script);
        $this->assertStringContainsString('HTTP_X_FORWARDED_PROTO', $script);
        $this->assertStringContainsString('talksasa_https', $script);
    }

    #[Test]
    public function it_detects_php_fpm_fallback_log_line(): void
    {
        $logs = 'Talksasa: nginx/php-fpm unavailable (nginx=missing php-fpm=missing), falling back to php -S (single-threaded)';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $finding = collect($findings)->firstWhere('id', 'php_builtin_dev_server');

        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
    }

    #[Test]
    public function it_detects_nginx_fastcgi_params_crash(): void
    {
        $logs = 'user-74-service-24-laravel: 2026/08/25 12:23:28 [emerg] 1#1: open() "/tmp/talksasa-php/fastcgi_params" failed (2: No such file or directory) in /tmp/talksasa-php/nginx.conf:37';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $finding = collect($findings)->firstWhere('id', 'nginx_boot_failed');

        $this->assertNotNull($finding);
        $this->assertSame('switch_php_production_runtime', $finding['treat_action']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertNotContains('php_builtin_dev_server', array_column($findings, 'id'));
    }

    #[Test]
    public function it_prefers_nginx_boot_failure_over_generic_upstream_and_php_dash_s(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'php_builtin_dev_server',
                'severity' => 'warning',
                'title' => 'PHP development server',
                'summary' => 'old logs',
                'evidence' => ['PHP 8.3.33 Development Server (http://0.0.0.0:80) started'],
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Switch to PHP-FPM',
                'manual_steps' => [],
            ]],
            [
                'findings' => [
                    [
                        'id' => 'nginx_boot_failed',
                        'severity' => 'critical',
                        'title' => 'nginx failed to start',
                        'summary' => 'fastcgi_params',
                        'evidence' => ['[emerg] open() "/tmp/talksasa-php/fastcgi_params" failed'],
                        'treat_action' => 'switch_php_production_runtime',
                        'treat_label' => 'Rebuild PHP-FPM runtime',
                        'manual_steps' => [],
                    ],
                    [
                        'id' => 'live_upstream_unreachable',
                        'severity' => 'critical',
                        'title' => 'proxy cannot reach the app',
                        'summary' => '502',
                        'evidence' => ['HTTP 502'],
                        'treat_action' => 'recreate_application',
                        'treat_label' => 'Recreate containers',
                        'manual_steps' => [],
                    ],
                ],
                'checks' => [
                    'http_status' => 502,
                    'restarting' => true,
                    'php_production_runtime' => false,
                ],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('nginx_boot_failed', $ids);
        $this->assertNotContains('php_builtin_dev_server', $ids);
        $this->assertNotContains('live_upstream_unreachable', $ids);
    }

    #[Test]
    public function it_drops_stale_php_dev_server_logs_when_live_runtime_is_php_fpm(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'php_builtin_dev_server',
                'severity' => 'warning',
                'title' => 'PHP development server',
                'summary' => 'old logs',
                'evidence' => ['PHP 8.3.33 Development Server (http://0.0.0.0:80) started'],
                'treat_action' => 'switch_php_production_runtime',
                'treat_label' => 'Switch to PHP-FPM',
                'manual_steps' => [],
            ]],
            [
                'findings' => [],
                'checks' => [
                    'php_production_runtime' => true,
                    'http_status' => 200,
                    'db_ok' => true,
                ],
            ]
        );

        $this->assertNotContains('php_builtin_dev_server', array_column($merged, 'id'));
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
    public function it_detects_missing_postgres_role(): void
    {
        $logs = '2026-09-08 18:46:27.378 UTC [65] FATAL:  role "postgres" does not exist';

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'nodejs');
        $finding = collect($findings)->firstWhere('id', 'postgres_role_missing');

        $this->assertNotNull($finding);
        $this->assertSame('sync_database_credentials', $finding['treat_action']);
        $this->assertSame('Repair DB credentials', $finding['treat_label']);
        $this->assertSame('critical', $finding['severity']);
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
    public function it_detects_intermittent_500s_on_the_same_path(): void
    {
        $logs = <<<'LOG'
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:23:44 +0000] "GET /get-total-unread HTTP/1.1" 200 72 "https://racegroup.co.ke/sells"
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:23:52 +0000] "GET /get-total-unread HTTP/1.1" 500 44 "https://racegroup.co.ke/contacts/11"
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:23:59 +0000] "GET /get-total-unread HTTP/1.1" 500 44 "https://racegroup.co.ke/sells/create"
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:27:48 +0000] "GET /sells HTTP/1.1" 200 38822 "-"
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:27:42 +0000] "GET /sells HTTP/1.1" 500 6622 "-"
user-74-service-24-laravel     | 10.201.0.1 - - [26/Aug/2026:11:30:29 +0000] "GET /sells HTTP/1.1" 500 6622 "-"
LOG;

        $summary = app(ContainerDoctorService::class)->summarizeAccessLogs($logs);
        $this->assertGreaterThanOrEqual(2, $summary['status_5xx']);
        $this->assertGreaterThanOrEqual(2, $summary['status_2xx']);

        $unread = collect($summary['mixed_paths'])->firstWhere('path', '/get-total-unread');
        $this->assertNotNull($unread);
        $this->assertSame(1, $unread['ok']);
        $this->assertSame(2, $unread['fail']);

        $finding = app(ContainerDoctorService::class)->intermittentAccessLogFinding($logs, [
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'database',
        ]);
        $this->assertNotNull($finding);
        $this->assertSame('intermittent_http_5xx', $finding['id']);
        $this->assertSame('tune_request_concurrency', $finding['treat_action']);
        $this->assertStringContainsString('get-total-unread', $finding['summary']);

        $fromLogs = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $this->assertNotContains('intermittent_http_5xx', array_column($fromLogs, 'id'));

        $relaxed = app(ContainerDoctorService::class)->intermittentAccessLogFinding($logs, [
            'SESSION_DRIVER' => 'cookie',
            'CACHE_STORE' => 'file',
        ]);
        $this->assertNotNull($relaxed);
        $this->assertSame('restart_application', $relaxed['treat_action']);
        $this->assertStringContainsString('compose', $relaxed['summary']);
        $this->assertStringContainsString('Restart application', $relaxed['manual_steps'][0]);

        $this->assertNull(app(ContainerDoctorService::class)->intermittentAccessLogFinding($logs, [
            'SESSION_DRIVER' => 'cookie',
            'CACHE_STORE' => 'file',
        ], 'cookie'));

        $wordpress = app(ContainerDoctorService::class)->intermittentAccessLogFinding($logs, [], null, 'wordpress');
        $this->assertNotNull($wordpress);
        $this->assertSame('restart_application', $wordpress['treat_action']);
        $this->assertStringContainsString('WordPress', $wordpress['summary']);
        $this->assertSame('restart_application', app(ContainerDoctorService::class)->normalizeFindingForStack([
            'treat_action' => 'tune_request_concurrency',
            'treat_label' => 'Relax session/cache locking',
            'manual_steps' => ['artisan'],
        ], 'wordpress')['treat_action']);

        $fromWordpressLogs = app(ContainerDoctorService::class)->analyzeLogs(
            "PHP Fatal error:  Maximum execution time of 30 seconds exceeded\n",
            'wordpress'
        );
        $timeout = collect($fromWordpressLogs)->firstWhere('id', 'php_max_execution_time');
        $this->assertNotNull($timeout);
        $this->assertSame('restart_application', $timeout['treat_action']);
        $this->assertStringContainsString('WordPress', $timeout['manual_steps'][0]);
    }

    #[Test]
    public function it_detects_php_fpm_max_children_and_compose_mail_warnings(): void
    {
        $logs = <<<'LOG'
time="2026-08-26T14:35:22+03:00" level=warning msg="The \"MAIL_USERNAME\" variable is not set. Defaulting to a blank string."
WARNING: [pool www] server reached pm.max_children setting (2), consider raising it
PHP Fatal error:  Maximum execution time of 30 seconds exceeded
user-74-service-24-laravel-db  | [Warning] [MY-010235] Following users were specified in CREATE USER IF NOT EXISTS but they already exist
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $ids = array_column($findings, 'id');

        $this->assertContains('php_fpm_max_children', $ids);
        $this->assertContains('php_max_execution_time', $ids);
        $this->assertContains('compose_unset_variable', $ids);
        $this->assertSame(
            'tune_request_concurrency',
            collect($findings)->firstWhere('id', 'php_fpm_max_children')['treat_action']
        );
        $this->assertSame(
            'fix_compose_interpolation',
            collect($findings)->firstWhere('id', 'compose_unset_variable')['treat_action']
        );
        $this->assertSame('info', collect($findings)->firstWhere('id', 'compose_unset_variable')['severity']);
    }

    #[Test]
    public function it_treats_artisan_cache_clear_1045_as_file_cache_not_storage_permissions(): void
    {
        $logs = <<<'LOG'
www-data@user-74-service-24-laravel:/app$ php artisan cache:clear
Illuminate\Database\QueryException
  SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.11' (using password: YES) (SQL: delete from `cache`)
www-data@user-74-service-24-laravel:/app$ php artisan optimize:clear
  cache ............................................................. 9ms FAIL
   Illuminate\Database\QueryException
  SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.11' (using password: YES) (SQL: delete from `cache`)
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $cache = collect($findings)->firstWhere('id', 'artisan_cache_uses_database');

        $this->assertNotNull($cache);
        $this->assertSame('use_file_cache', $cache['treat_action']);
        $this->assertSame('critical', $cache['severity']);
        $this->assertContains('mysql_access_denied', array_column($findings, 'id'));
    }

    #[Test]
    public function it_keeps_intermittent_500s_when_the_homepage_is_ok(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'intermittent_http_5xx',
                'severity' => 'warning',
                'title' => 'Intermittent HTTP 500s',
                'treat_action' => 'tune_request_concurrency',
            ]],
            [
                'findings' => [],
                'checks' => ['http_status' => 200, 'db_ok' => true, 'table_count' => 91],
            ]
        );

        $this->assertContains('intermittent_http_5xx', array_column($merged, 'id'));
    }

    #[Test]
    public function it_drops_intermittent_500s_when_runtime_sessions_are_already_cookie(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'intermittent_http_5xx',
                'severity' => 'critical',
                'title' => 'Intermittent HTTP 500s',
                'treat_action' => 'restart_application',
            ]],
            [
                'findings' => [],
                'checks' => [
                    'http_status' => 200,
                    'db_ok' => true,
                    'session_driver' => 'cookie',
                    'cache_store' => 'file',
                    'session_driver_runtime' => 'cookie',
                    'http_5xx_count' => 345,
                ],
            ]
        );

        $this->assertNotContains('intermittent_http_5xx', array_column($merged, 'id'));
    }

    #[Test]
    public function it_keeps_intermittent_500s_when_env_says_cookie_because_workers_may_be_stale(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'intermittent_http_5xx',
                'severity' => 'critical',
                'title' => 'Intermittent HTTP 500s',
                'treat_action' => 'restart_application',
            ]],
            [
                'findings' => [],
                'checks' => [
                    'http_status' => 200,
                    'db_ok' => true,
                    'session_driver' => 'cookie',
                    'cache_store' => 'file',
                    'session_driver_runtime' => 'database',
                    'http_5xx_count' => 345,
                ],
            ]
        );

        $this->assertContains('intermittent_http_5xx', array_column($merged, 'id'));
    }

    #[Test]
    public function it_drops_intermittent_500s_when_live_database_auth_failed(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'intermittent_http_5xx',
                'severity' => 'warning',
                'title' => 'Intermittent HTTP 500s',
                'treat_action' => 'tune_request_concurrency',
            ]],
            [
                'findings' => [[
                    'id' => 'live_db_connection_failed',
                    'severity' => 'critical',
                    'title' => 'Live check: database authentication failed',
                    'treat_action' => 'sync_database_credentials',
                ]],
                'checks' => ['http_status' => 500, 'db_ok' => false],
            ]
        );

        $this->assertNotContains('intermittent_http_5xx', array_column($merged, 'id'));
        $this->assertContains('live_db_connection_failed', array_column($merged, 'id'));
    }

    #[Test]
    public function it_detects_mysql_access_denied_from_docker_overlay_ip(): void
    {
        $logs = <<<'LOG'
SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26' (using password: YES) (SQL: delete from `cache`)
Please provide a valid cache path.
LOG;

        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'laravel');
        $ids = array_column($findings, 'id');

        $this->assertContains('mysql_access_denied', $ids);
        $this->assertContains('storage_permission_denied', $ids);

        $mysql = collect($findings)->firstWhere('id', 'mysql_access_denied');
        $this->assertSame('sync_database_credentials', $mysql['treat_action']);
        $this->assertSame('critical', $mysql['severity']);
    }

    #[Test]
    public function it_does_not_downgrade_mysql_access_denied_when_env_looks_aligned(): void
    {
        $service = new Service;
        $service->id = 24;
        $service->user_id = 74;
        $service->setRelation('containerDeployment', new ContainerDeployment([
            'env_values' => [
                'DB_DATABASE' => 's24_db',
                'DB_USERNAME' => 'u74_s24',
                'DB_PASSWORD' => 'secret',
                'MYSQL_PASSWORD' => 'secret',
                'DATABASE_URL' => 'mysql://u74_s24:secret@db:3306/s24_db',
            ],
        ]));

        $findings = [[
            'id' => 'mysql_access_denied',
            'severity' => 'critical',
            'title' => 'MySQL access denied',
            'summary' => 'Rejected',
            'evidence' => ["Access denied for user 'u74_s24'@'10.201.0.26'"],
            'treat_action' => 'sync_database_credentials',
            'treat_label' => 'Repair DB credentials',
            'manual_steps' => [],
        ]];

        $annotated = app(ContainerDoctorService::class)->annotateFindingsWithLiveStatus($service, $findings);

        $this->assertSame('critical', $annotated[0]['severity']);
        $this->assertArrayNotHasKey('stale', $annotated[0]);
        $this->assertSame('sync_database_credentials', $annotated[0]['treat_action']);
    }

    #[Test]
    public function it_keeps_mysql_access_denied_when_the_site_is_5xx_and_db_was_not_ok(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'mysql_access_denied',
                'severity' => 'critical',
                'title' => 'MySQL access denied',
                'treat_action' => 'sync_database_credentials',
            ]],
            [
                'findings' => [[
                    'id' => 'live_http_5xx',
                    'severity' => 'critical',
                    'title' => 'HTTP 500',
                    'treat_action' => 'fix_storage_permissions',
                ]],
                'checks' => ['http_status' => 500, 'db_ok' => false],
            ]
        );

        $ids = array_column($merged, 'id');
        $this->assertContains('mysql_access_denied', $ids);
        $this->assertContains('live_http_5xx', $ids);
    }

    #[Test]
    public function laravel_cache_clear_does_not_require_a_working_database_cache_table(): void
    {
        $script = app(ContainerDoctorService::class)->laravelCacheClearScript('/app');

        $this->assertStringNotContainsString('optimize:clear', $script);
        $this->assertStringNotContainsString('set -e', $script);
        $this->assertStringContainsString('CACHE_STORE=file', $script);
        $this->assertStringContainsString('CACHE_DRIVER=file', $script);
        $this->assertStringContainsString('php artisan config:clear', $script);
        $this->assertStringContainsString('php artisan cache:clear', $script);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!CACHE_STORE=file CACHE_DRIVER=file )php artisan cache:clear/',
            $script
        );
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
    public function it_keeps_panel_password_when_database_url_disagrees(): void
    {
        $probe = app(ContainerDoctorService::class)->envForRuntimeDatabaseProbe([
            'DB_DATABASE' => 's163_db',
            'DB_USERNAME' => 'u193_s163',
            'DB_PASSWORD' => 'panel-password',
            'DATABASE_URL' => 'postgresql://u193_s163:url-password@db:5432/s163_db',
        ], 'postgresql');

        $this->assertSame('panel-password', $probe['DB_PASSWORD']);
        $this->assertSame('s163_db', $probe['DB_DATABASE']);
    }

    #[Test]
    public function it_fills_empty_password_from_database_url(): void
    {
        $probe = app(ContainerDoctorService::class)->envForRuntimeDatabaseProbe([
            'DB_DATABASE' => 's163_db',
            'DB_USERNAME' => 'u193_s163',
            'DATABASE_URL' => 'postgresql://u193_s163:url-password@db:5432/s163_db',
        ], 'postgresql');

        $this->assertSame('url-password', $probe['DB_PASSWORD']);
        $this->assertSame('s163_db', $probe['DB_DATABASE']);
    }

    #[Test]
    public function it_overlays_panel_database_credentials_over_stale_live_url(): void
    {
        $overlay = app(ContainerDoctorService::class)->overlayPanelDatabaseCredentials(
            [
                'DB_PASSWORD' => 'grant-password',
                'DATABASE_URL' => 'mysql://u74_s24:grant-password@db:3306/s24_db',
            ],
            [
                'DB_PASSWORD' => 'grant-password',
                'DATABASE_URL' => 'mysql://u74_s24:stale-url-password@db:3306/s24_db',
            ]
        );

        $this->assertSame('grant-password', $overlay['DB_PASSWORD']);
        $this->assertStringContainsString('grant-password', $overlay['DATABASE_URL']);
        $this->assertStringNotContainsString('stale-url-password', $overlay['DATABASE_URL']);

        $wordpress = app(ContainerDoctorService::class)->overlayPanelDatabaseCredentials(
            [
                'WORDPRESS_DB_HOST' => 'mysql',
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'wp-secret',
            ],
            [
                'DB_HOST' => 'db',
                'WORDPRESS_DB_HOST' => 'db',
                'WORDPRESS_DB_PASSWORD' => 'stale',
            ]
        );
        $this->assertSame('mysql', $wordpress['WORDPRESS_DB_HOST']);
        $this->assertSame('wp-secret', $wordpress['WORDPRESS_DB_PASSWORD']);

        $probe = app(ContainerDoctorService::class)->envForRuntimeDatabaseProbe(
            [
                'DB_DATABASE' => 's373_db',
                'DB_USERNAME' => 'u488_s373',
                'DB_PASSWORD' => 'panel-password',
                'DATABASE_URL' => 'mysql://u488_s373:panel-password@mysql:3306/s373_db',
                'WORDPRESS_DB_HOST' => 'mysql:3306',
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'wp-secret',
            ],
            'mysql'
        );
        $this->assertSame('wordpress', $probe['DB_DATABASE']);
        $this->assertSame('wordpress', $probe['DB_USERNAME']);
        $this->assertSame('wp-secret', $probe['DB_PASSWORD']);
        $this->assertSame('mysql', $probe['DB_HOST']);
    }

    #[Test]
    public function wordpress_repair_steps_rewrite_wp_config_not_laravel_env(): void
    {
        $steps = app(ContainerDoctorService::class)
            ->repairDatabaseCredentialsManualSteps('wordpress', 'mysql');

        $this->assertStringContainsString('wp-config.php', $steps[0]);
        $this->assertStringContainsString('WORDPRESS_DB_', $steps[0]);
        $this->assertStringContainsString('mysql_native_password', $steps[0]);
        $this->assertStringContainsString('talksasa-net', $steps[0]);
        $this->assertStringNotContainsString('PHP-FPM', $steps[0]);
    }

    #[Test]
    public function wordpress_container_name_wins_over_php_listing_slug(): void
    {
        $service = new Service;
        $service->setRelation('product', null);
        $service->setRelation('containerDeployment', new ContainerDeployment([
            'container_name' => 'user-488-service-373-wordpress',
        ]));

        $this->assertSame('wordpress', app(ContainerDoctorService::class)->resolveStackSlug($service));
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
    public function it_drops_storage_permission_log_findings_when_the_site_returns_http_ok(): void
    {
        $merged = app(ContainerDoctorService::class)->mergeLogAndLiveFindings(
            [[
                'id' => 'storage_permission_denied',
                'severity' => 'warning',
                'title' => 'Storage / cache permission problem',
                'evidence' => ['Please provide a valid cache path'],
            ]],
            [
                'findings' => [],
                'checks' => ['http_status' => 200, 'db_ok' => true, 'table_count' => 91],
            ]
        );

        $this->assertNotContains('storage_permission_denied', array_column($merged, 'id'));
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
    public function node_upstream_unreachable_offers_start_the_node_app_not_recreate(): void
    {
        $treat = app(ContainerDoctorService::class)->unreachableUpstreamTreatment('nodejs');

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertSame('Start the Node app', $treat['treat_label']);
        $this->assertStringContainsString('same start command', $treat['summary']);
        $this->assertStringContainsString('Do not Recreate', implode(' ', $treat['manual_steps']));
    }

    #[Test]
    public function laravel_upstream_unreachable_still_offers_recreate(): void
    {
        $treat = app(ContainerDoctorService::class)->unreachableUpstreamTreatment('laravel');

        $this->assertSame('recreate_application', $treat['treat_action']);
        $this->assertSame('Recreate containers', $treat['treat_label']);
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
    public function wordpress_htaccess_header_module_offers_apache_repair(): void
    {
        $logs = <<<'LOG'
[core:alert] /var/www/html/.htaccess: Invalid command 'Header', perhaps misspelled or defined by a module not included in the server configuration
GET /dashboard HTTP/1.1" 500 860
LOG;
        $findings = app(ContainerDoctorService::class)->analyzeLogs($logs, 'wordpress');
        $finding = collect($findings)->firstWhere('id', 'wordpress_htaccess_header_module_missing');

        $this->assertNotNull($finding);
        $this->assertSame('fix_wordpress_apache_modules', $finding['treat_action']);

        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            ['db_ok' => true, 'http_status' => 500],
            [$logs],
            'wordpress'
        );
        $this->assertSame('fix_wordpress_apache_modules', $treat['treat_action']);
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
    public function http_500_does_not_recommend_repair_db_when_live_pdo_already_works(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 91,
                'http_status' => 500,
            ],
            [
                'Please provide a valid cache path.',
                'SQLSTATE[HY000] [2002] Connection refused at /app/vendor/laravel/framework',
                "SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26' (using password: YES)",
            ],
            'laravel'
        );

        $this->assertSame('fix_storage_permissions', $treat['treat_action']);
        $this->assertStringNotContainsString('Repair grants', $treat['summary']);
    }

    #[Test]
    public function http_500_with_live_db_and_no_cache_path_restarts_workers_instead_of_repairing_mysql(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 91,
                'http_status' => 500,
            ],
            [
                "SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26' (using password: YES) (SQL: select * from `sessions`)",
            ],
            'laravel'
        );

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertStringContainsString('sessions', $treat['summary']);
        $this->assertStringContainsString('MySQL', $treat['summary']);
    }

    #[Test]
    public function ambiguous_laravel_db_host_is_the_shared_network_alias(): void
    {
        $doctor = app(ContainerDoctorService::class);

        $this->assertTrue($doctor->isAmbiguousLaravelDatabaseHost('db'));
        $this->assertTrue($doctor->isAmbiguousLaravelDatabaseHost('localhost'));
        $this->assertTrue($doctor->isAmbiguousLaravelDatabaseHost('127.0.0.1'));
        $this->assertTrue($doctor->isAmbiguousLaravelDatabaseHost('localhost:/var/lib/mysql/mysql.sock'));
        $this->assertTrue($doctor->isAmbiguousLaravelDatabaseHost('/var/lib/mysql/mysql.sock'));
        $this->assertFalse($doctor->isAmbiguousLaravelDatabaseHost('user-74-service-24-laravel-db'));
        $this->assertFalse($doctor->isAmbiguousLaravelDatabaseHost(null));
    }

    #[Test]
    public function login_502_with_working_homepage_is_nginx_header_buffers(): void
    {
        $doctor = app(ContainerDoctorService::class);

        $this->assertTrue($doctor->loginPathLooksLikeHeaderBufferFailure(200, 502));
        $this->assertTrue($doctor->loginPathLooksLikeHeaderBufferFailure(302, 502));
        $this->assertFalse($doctor->loginPathLooksLikeHeaderBufferFailure(200, 200));
        $this->assertFalse($doctor->loginPathLooksLikeHeaderBufferFailure(500, 502));
        $this->assertFalse($doctor->loginPathLooksLikeHeaderBufferFailure(200, 500));
    }

    #[Test]
    public function laravel_stale_vhost_is_a_critical_login_buffer_card(): void
    {
        $finding = app(ContainerDoctorService::class)->withLaravelProxyBufferContext(
            [
                'id' => 'live_stale_proxy_vhost',
                'severity' => 'warning',
                'treat_action' => 'refresh_domain_proxy',
                'treat_label' => 'Refresh web proxy',
            ],
            'laravel'
        );

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('refresh_domain_proxy', $finding['treat_action']);
        $this->assertStringContainsString('/home', $finding['summary']);
    }

    #[Test]
    public function origin_login_probe_hits_node_nginx_not_cloudflare(): void
    {
        $cmd = app(ContainerDoctorService::class)->originLoginProbeCommand('racegroup.co.ke', true);

        $this->assertStringContainsString('--resolve', $cmd);
        $this->assertStringContainsString('127.0.0.1', $cmd);
        $this->assertStringContainsString('https://racegroup.co.ke/home', $cmd);
    }

    #[Test]
    public function it_detects_http_css_on_an_https_page(): void
    {
        $html = <<<'HTML'
<link rel="canonical" href="http://tajmaal.co.ke">
<link rel="stylesheet" href="http://tajmaal.co.ke/website/css/bootstrap.min.css">
<link rel="stylesheet" href="http://tajmaal.co.ke/website/css/styles.css">
<script src="http://tajmaal.co.ke/website/js/custom.js"></script>
<img src="https://tajmaal.co.ke/storage/media/5/TAJMAAL-PNG.png">
<a href="http://tajmaal.co.ke/contact">Contact</a>
HTML;

        $doctor = app(ContainerDoctorService::class);
        $assets = $doctor->httpsPageHttpAssetUrls($html, 'https://tajmaal.co.ke');

        $this->assertContains('http://tajmaal.co.ke/website/css/bootstrap.min.css', $assets);
        $this->assertContains('http://tajmaal.co.ke/website/js/custom.js', $assets);
        $this->assertCount(3, $assets);
        $this->assertTrue($doctor->appUrlIsHttpWhileLiveIsHttps('http://tajmaal.co.ke', 'https://tajmaal.co.ke'));
        $this->assertFalse($doctor->appUrlIsHttpWhileLiveIsHttps('https://tajmaal.co.ke', 'https://tajmaal.co.ke'));
        $this->assertSame('https://tajmaal.co.ke', $doctor->canonicalHttpsAppUrl('https://tajmaal.co.ke/'));
        $this->assertStringContainsString('head -c 200000', $doctor->homepageBodyProbeCommand('https://tajmaal.co.ke'));
    }

    #[Test]
    public function it_detects_storage_media_404s_from_html_and_access_logs(): void
    {
        $html = <<<'HTML'
<img src="https://tajmaal.co.ke/storage/media/48/2brkitchen.jpg">
<img src="https://tajmaal.co.ke/storage/media/132/mas.jpg">
<div style="background-image: url(https://tajmaal.co.ke/storage/media/148/PAGE-BANNER.jpg)"></div>
HTML;
        $logs = <<<'LOG'
user-85-service-27-laravel  | 10.201.0.1 - - [26/Aug/2026:15:10:01 +0000] "GET /storage/media/48/2brkitchen.jpg HTTP/1.1" 404 5486
user-85-service-27-laravel  | 10.201.0.1 - - [26/Aug/2026:15:10:01 +0000] "GET /storage/media/123/gym.jpg HTTP/1.1" 404 5486
user-85-service-27-laravel  | 10.201.0.1 - - [26/Aug/2026:15:10:02 +0000] "GET /2-bedroom HTTP/1.1" 200 22000
LOG;

        $doctor = app(ContainerDoctorService::class);
        $urls = $doctor->storageMediaUrlsFromHtml($html, 'https://tajmaal.co.ke');
        $this->assertContains('https://tajmaal.co.ke/storage/media/48/2brkitchen.jpg', $urls);
        $this->assertContains('https://tajmaal.co.ke/storage/media/148/PAGE-BANNER.jpg', $urls);

        $asset404s = $doctor->staticAsset404s($logs);
        $this->assertSame('/storage/media/48/2brkitchen.jpg', $asset404s[0]['path']);
        $this->assertSame(1, $asset404s[0]['count']);
        $this->assertNotContains('/2-bedroom', array_column($asset404s, 'path'));

        $parsed = $doctor->parseAssetStatusLines("404 https://tajmaal.co.ke/storage/media/48/2brkitchen.jpg\n200 https://tajmaal.co.ke/website/css/styles.css\n");
        $this->assertSame(404, $parsed[0]['status']);
        $this->assertStringContainsString('/storage/media/48/2brkitchen.jpg', $doctor->assetStatusProbeCommand($urls));
    }

    #[Test]
    public function http_500_with_live_pdo_and_host_db_pins_unique_sidecar_dns(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 91,
                'http_status' => 500,
                'laravel_db_host' => 'db',
            ],
            [
                'SQLSTATE[HY000] [2002] Connection refused at /app/vendor/laravel/framework',
                'PDOException(code: 2002): SQLSTATE[HY000] [2002] Connection refused',
                '#2 mysql:host=db;port=3306;dbname=s24_db',
            ],
            'laravel'
        );

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertStringContainsString('mysql:host=db', $treat['summary']);
        $this->assertStringContainsString('talksasa-net', $treat['summary']);
    }

    #[Test]
    public function http_500_still_repairs_credentials_when_live_pdo_failed(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => false,
                'table_count' => null,
                'http_status' => 500,
            ],
            [
                "SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26' (using password: YES)",
            ],
            'laravel'
        );

        $this->assertSame('sync_database_credentials', $treat['treat_action']);
    }

    #[Test]
    public function http_500_with_codeigniter_paths_fatal_rewrites_the_front_controller(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_uses_mysql_ext' => true,
                'php_fatal' => 'Fatal error: Uncaught Error: Failed opening required \'/app/../app/Config/Paths.php\' in /app/index.php:48',
            ],
            [],
            'php'
        );

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertStringContainsString('CodeIgniter', $treat['summary']);
        $this->assertStringContainsString('Config/Paths.php', $treat['summary']);
    }

    #[Test]
    public function http_500_with_missing_ci_app_and_da_legacy_imports_siblings(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => [],
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('import_da_codeigniter_app', $treat['treat_action']);
        $this->assertSame('Import CodeIgniter app folder', $treat['treat_label']);
        $this->assertStringContainsString('public_html', $treat['summary']);
    }

    #[Test]
    public function http_500_with_loaded_ci_paths_rewrites_stale_database_hostname(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => false,
                'php_ci_db_host' => 'localhost',
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertStringContainsString('hostname', $treat['summary']);
        $this->assertStringContainsString('localhost', $treat['summary']);
        $this->assertStringNotContainsString('../app/Config/Paths.php require', $treat['summary']);
    }

    #[Test]
    public function http_500_with_loaded_ci_paths_but_no_system_imports_siblings(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => false,
                'php_ci_vendor_system' => false,
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('import_da_codeigniter_app', $treat['treat_action']);
        $this->assertStringContainsString('system/', $treat['summary']);
    }

    #[Test]
    public function http_500_with_vendor_system_links_instead_of_recreating(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => false,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('link_codeigniter_system', $treat['treat_action']);
        $this->assertSame('Link system/ to vendor', $treat['treat_label']);
        $this->assertStringContainsString('vendor/codeigniter4', $treat['summary']);
        $this->assertStringNotContainsString('recreates the app', $treat['summary']);
    }

    #[Test]
    public function http_500_with_loaded_ci_runtime_heals_env_instead_of_restart(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('heal_codeigniter_runtime', $treat['treat_action']);
        $this->assertSame('Heal CodeIgniter runtime', $treat['treat_label']);
        $this->assertStringContainsString('encryption.key', $treat['summary']);
        $this->assertStringNotContainsString('recreates the app', $treat['summary']);
    }

    #[Test]
    public function http_500_for_ospos_installs_official_app_and_keeps_mysql(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_fatal' => 'Class "Config\\Locale" not found',
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'php_ci_db_user' => 'u483_s426',
                'php_ci_ospos' => true,
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('install_ospos_application', $treat['treat_action']);
        $this->assertSame('Install Open Source POS (keep database)', $treat['treat_label']);
        $this->assertStringContainsString('opensourcepos', $treat['summary']);
        $this->assertStringContainsString('keeps MySQL', $treat['summary']);
        $this->assertStringContainsString('leave Reset database unchecked', $treat['summary']);
    }

    #[Test]
    public function http_500_after_official_ospos_heals_allowed_hostnames_instead_of_restart(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'php_ci_db_user' => 'u483_s426',
                'php_ci_ospos' => true,
                'php_ci_allowed_hostnames' => false,
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('heal_codeigniter_runtime', $treat['treat_action']);
        $this->assertStringContainsString('allowedHostnames', $treat['summary']);
        $this->assertStringContainsString('ALLOWED_HOSTNAMES', $treat['summary']);
        $this->assertStringNotContainsString('recreates the app', $treat['summary']);
        $this->assertStringContainsString('Host whitelist is set', app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'php_ci_db_user' => 'u483_s426',
                'php_ci_ospos' => true,
                'php_ci_allowed_hostnames' => true,
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        )['summary']);
        $this->assertSame('install_ospos_application', app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_fatal' => 'Class "Config\\Locale" not found',
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_ospos' => true,
                'php_ci_vendor_system' => true,
            ],
            [],
            'php'
        )['treat_action']);
    }

    #[Test]
    public function http_500_with_sidecar_creds_and_missing_mysqli_still_heals(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_index_require' => "require __DIR__ . '/app/Config/Paths.php';",
                'php_paths_php' => ['/app/app/Config/Paths.php'],
                'php_ci_system' => true,
                'php_ci_vendor_system' => true,
                'php_ci_db_host' => 'user-483-service-426-static-site-db',
                'php_ci_db_user' => 'u483_s426',
                'php_ci_mysqli' => false,
                'da_can_import_ci_app' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('heal_codeigniter_runtime', $treat['treat_action']);
        $this->assertStringContainsString('MySQLi', $treat['summary']);
        $this->assertStringNotContainsString('DirectAdmin DB user/password', $treat['summary']);
        $this->assertStringNotContainsString('recreates the app', $treat['summary']);
    }

    #[Test]
    public function http_500_with_live_pdo_and_mysql_ext_installs_the_shim_on_restart(): void
    {
        $treat = app(ContainerDoctorService::class)->resolveHttp500Treatment(
            [
                'db_ok' => true,
                'table_count' => 39,
                'http_status' => 500,
                'php_uses_mysql_ext' => true,
            ],
            [],
            'php'
        );

        $this->assertSame('restart_application', $treat['treat_action']);
        $this->assertStringContainsString('mysql_*', $treat['summary']);
        $this->assertStringContainsString('shim', $treat['summary']);
    }

    #[Test]
    public function php_restart_is_not_treatment_success_while_get_still_500s(): void
    {
        $doctor = app(ContainerDoctorService::class);

        $failed = $doctor->phpRestartTreatmentOutcome('php', 500, [
            'fatal' => 'Fatal error: Call to undefined function mysql_connect()',
            'uses_mysql_ext' => true,
            'index_files' => ['/app/index.php (800 bytes)'],
            'lint' => [],
        ]);
        $this->assertFalse($failed['success']);
        $this->assertStringContainsString('HTTP 500', $failed['message']);
        $this->assertStringContainsString('mysql_connect', $failed['message']);

        $ok = $doctor->phpRestartTreatmentOutcome('php', 200, []);
        $this->assertTrue($ok['success']);
        $this->assertStringContainsString('sidecar left running', $ok['message']);

        $portOnly = $doctor->phpRestartTreatmentOutcome('nodejs', 500, []);
        $this->assertTrue($portOnly['success']);
    }

    #[Test]
    public function newest_unique_lines_keep_the_latest_error_not_the_oldest_unique_mix(): void
    {
        $lines = app(ContainerDoctorService::class)->newestUniqueLines([
            'Please provide a valid cache path.',
            'SQLSTATE[HY000] [2002] Connection refused',
            'Please provide a valid cache path.',
            "SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26'",
        ], 2);

        $this->assertSame([
            'Please provide a valid cache path.',
            "SQLSTATE[HY000] [1045] Access denied for user 'u74_s24'@'10.201.0.26'",
        ], $lines);
        $this->assertNotContains('SQLSTATE[HY000] [2002] Connection refused', $lines);
    }

    #[Test]
    public function php_fpm_reload_targets_the_oldest_master_pid(): void
    {
        $script = app(ContainerDoctorService::class)->phpFpmReloadScript();

        $this->assertStringContainsString('pgrep -o php-fpm', $script);
        $this->assertStringContainsString('kill -USR2', $script);
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

    #[Test]
    public function empty_database_finding_imports_from_directadmin_when_legacy_exists(): void
    {
        $service = new Service;
        $service->provisioning_driver_key = 'container';
        $service->service_meta = [
            'da_legacy' => [
                'username' => 'u483',
                'da_node_id' => 9,
                'domain' => 'roadtrip.example.com',
                'stack' => 'static_or_php',
                'databases' => [['name' => 'roadtrip_db']],
            ],
        ];

        $finding = app(ContainerDoctorService::class)->emptyDatabaseFinding(
            $service,
            's426_db',
            true,
            'php',
            0
        );

        $this->assertNotNull($finding);
        $this->assertSame('live_empty_database', $finding['id']);
        $this->assertSame('import_da_database', $finding['treat_action']);
        $this->assertSame('Import DirectAdmin database', $finding['treat_label']);
        $this->assertStringContainsString('converted from DirectAdmin', $finding['summary']);
    }

    #[Test]
    public function empty_database_finding_uses_migrate_fresh_without_directadmin_legacy(): void
    {
        $service = new Service;
        $service->provisioning_driver_key = 'container';
        $service->service_meta = [];

        $finding = app(ContainerDoctorService::class)->emptyDatabaseFinding(
            $service,
            's426_db',
            true,
            'php',
            0
        );

        $this->assertSame('migrate_fresh', $finding['treat_action']);
        $this->assertSame('Rebuild schema (migrate:fresh)', $finding['treat_label']);
    }

    #[Test]
    public function empty_database_finding_without_artisan_is_null_unless_directadmin_legacy(): void
    {
        $service = new Service;
        $service->provisioning_driver_key = 'container';
        $service->service_meta = [];

        $this->assertNull(app(ContainerDoctorService::class)->emptyDatabaseFinding(
            $service,
            's426_db',
            false,
            'php',
            null
        ));
    }
}
