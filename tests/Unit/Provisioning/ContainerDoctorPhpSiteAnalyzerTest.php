<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorPhpSiteAnalyzer;
use App\Services\Provisioning\ContainerDoctorWordPressAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorPhpSiteAnalyzerTest extends TestCase
{
    private function analyzer(): ContainerDoctorPhpSiteAnalyzer
    {
        return new ContainerDoctorPhpSiteAnalyzer(new ContainerDoctorWordPressAnalyzer);
    }

    private function raw(int $status, string $url, int $redirects, string $body = '<html><body>Hello there visitor</body></html>'): string
    {
        return $body."\n__TALKSASA_HTTP__ status={$status} size=".strlen($body)." url={$url} redirects={$redirects}";
    }

    #[Test]
    public function it_sees_where_the_homepage_lands(): void
    {
        $page = $this->analyzer()->classify($this->raw(404, 'http://127.0.0.1:32001/install', 1, 'Not Found'));

        $this->assertSame(404, $page['status']);
        $this->assertSame('/install', $page['final_path']);
        $this->assertSame(1, $page['redirects']);
        $this->assertTrue($page['installer']);

        $this->assertTrue($this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/install/index.php?step=1', 2))['installer']);
        $this->assertTrue($this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/installer/', 1))['installer']);
        $this->assertFalse($this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/installations/list', 1))['installer'], 'a real page whose name merely starts with install');
        $this->assertFalse($this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/', 0))['installer']);
        $this->assertNull($this->analyzer()->classify('curl: (7) Failed to connect')['status']);
    }

    #[Test]
    public function an_installer_redirect_that_404s_on_an_empty_database_points_at_the_import(): void
    {
        $page = $this->analyzer()->classify($this->raw(404, 'http://127.0.0.1:32001/install', 1, 'Not Found'));
        $findings = $this->analyzer()->findings($page, ['table_count' => 0, 'db_ok' => true], 'php', true);

        $this->assertCount(1, $findings);
        $this->assertSame('php_install_redirect', $findings[0]['id']);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertStringContainsString('does not exist', $findings[0]['title']);
        $this->assertStringContainsString('database has no tables', $findings[0]['summary']);
        $this->assertStringContainsString('pulled from DirectAdmin', $findings[0]['summary']);
        $this->assertContains('table_count=0', $findings[0]['evidence']);
        $this->assertContains('final URL: http://127.0.0.1:32001/install', $findings[0]['evidence']);
        $this->assertStringContainsString('import the site', $findings[0]['manual_steps'][0]);
        $this->assertStringContainsString('Do not try to reinstall', $findings[0]['manual_steps'][1]);
        $this->assertNull($findings[0]['treat_action'], 'an empty database is fixed by the import, not by faking a marker');
    }

    #[Test]
    public function a_live_installer_with_tables_present_blames_the_marker_and_warns_against_reinstalling(): void
    {
        $page = $this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/install/', 1, '<h1>Welcome to the installer</h1>'));
        $findings = $this->analyzer()->findings($page, ['table_count' => 143, 'db_ok' => true], 'laravel', false);

        $this->assertSame('php_install_redirect', $findings[0]['id']);
        $this->assertSame('The homepage is showing its installer', $findings[0]['title']);
        $this->assertStringContainsString('installed marker', $findings[0]['summary']);
        $this->assertStringNotContainsString('DirectAdmin', $findings[0]['summary']);
        $this->assertStringContainsString('storage/installed', $findings[0]['manual_steps'][0]);
        $this->assertStringContainsString('Do not run it over customer data', $findings[0]['manual_steps'][1]);
        $this->assertSame('mark_php_app_installed', $findings[0]['treat_action']);
        $this->assertSame('Mark as installed', $findings[0]['treat_label']);

        $unreachableDb = $this->analyzer()->findings($page, ['table_count' => null, 'db_ok' => false], 'php', false);
        $this->assertStringContainsString('cannot connect to its database', $unreachableDb[0]['summary']);
        $this->assertNull($unreachableDb[0]['treat_action']);
        $this->assertStringContainsString('Repair DB credentials', $unreachableDb[0]['manual_steps'][0]);
    }

    #[Test]
    public function loops_missing_pages_and_failing_targets_are_named_and_a_healthy_page_is_left_alone(): void
    {
        $loop = $this->analyzer()->findings($this->analyzer()->classify($this->raw(301, 'https://example.test/', 5, '')), [], 'php', false);
        $this->assertSame('php_redirect_loop', $loop[0]['id']);

        $missing = $this->analyzer()->findings($this->analyzer()->classify($this->raw(404, 'http://127.0.0.1:32001/home', 1, 'Not Found')), [], 'php', false);
        $this->assertSame('php_redirect_to_missing_page', $missing[0]['id']);
        $this->assertSame('warning', $missing[0]['severity']);

        $failed = $this->analyzer()->findings($this->analyzer()->classify($this->raw(500, 'http://127.0.0.1:32001/dashboard', 1, 'Whoops')), [], 'laravel', false);
        $this->assertSame('php_redirect_target_failed', $failed[0]['id']);
        $this->assertStringContainsString('HTTP 500', $failed[0]['title']);

        $this->assertSame([], $this->analyzer()->findings($this->analyzer()->classify($this->raw(200, 'http://127.0.0.1:32001/en', 1)), [], 'php', false), 'a redirect that lands on a working page is fine');
        $this->assertSame([], $this->analyzer()->findings($this->analyzer()->classify('curl: (7) Failed to connect'), [], 'php', false), 'unreachable is reported by the infrastructure checks');
    }
}
