<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerDoctorPhpSiteAnalyzer;
use App\Services\Provisioning\ContainerDoctorPhpSiteTreatments;
use App\Services\Provisioning\ContainerDoctorWordPressAnalyzer;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDoctorPhpSiteTreatmentsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-php-treat-'.uniqid();
        File::ensureDirectoryExists($this->root.'/routes');
        File::ensureDirectoryExists($this->root.'/app/Http/Middleware');
        File::ensureDirectoryExists($this->root.'/config');
        File::ensureDirectoryExists($this->root.'/storage');
        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function treatments(): ContainerDoctorPhpSiteTreatments
    {
        return new ContainerDoctorPhpSiteTreatments(
            app(DirectAdminToContainerMigrationService::class),
            new ContainerDoctorPhpSiteAnalyzer(new ContainerDoctorWordPressAnalyzer),
            app(ContainerIncidentService::class),
        );
    }

    private function bash(string $command): string
    {
        return (string) shell_exec('bash -c '.escapeshellarg($command).' 2>&1');
    }

    #[Test]
    public function every_command_parses_as_bash(): void
    {
        $t = $this->treatments();
        foreach ([
            $t->probeCommand('/opt/talksasa/containers/x/app'),
            $t->applyMarkersCommand('/opt/talksasa/containers/x/app', ['storage/installed', 'install/installed.txt']),
            $t->cacheClearScript('/opt/talksasa/containers/x/app/backend', '/opt/talksasa/containers/x/app'),
        ] as $command) {
            $file = $this->root.'/cmd-'.uniqid().'.sh';
            File::put($file, $command);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
        $this->assertStringContainsString("cd '/app/backend'", $t->cacheClearScript('/opt/talksasa/containers/x/app/backend', '/opt/talksasa/containers/x/app'));
        $this->assertStringContainsString("cd '/app'", $t->cacheClearScript('/opt/talksasa/containers/x/app', '/opt/talksasa/containers/x/app'));
    }

    #[Test]
    public function it_reads_the_marker_file_and_env_key_the_app_checks_and_ignores_unrelated_file_checks(): void
    {
        File::put($this->root.'/routes/web.php', <<<'PHP'
<?php
if (! file_exists(storage_path('installed'))) {
    return redirect('/install');
}
if (! file_exists(base_path('.env'))) { abort(500); }
PHP);
        File::put($this->root.'/app/Http/Middleware/CheckInstalled.php', <<<'PHP'
<?php
class CheckInstalled {
    public function handle($request, $next) {
        if (! config('app.installed') || env('APP_INSTALLED') !== true) {
            return redirect()->to('/install');
        }
        return $next($request);
    }
}
PHP);
        File::put($this->root.'/config/app.php', "<?php\nreturn [\n    'installed' => env('APP_INSTALLED', false),\n    'name' => env('APP_NAME', 'x'),\n];\n");
        File::put($this->root.'/vendor/acme/pkg/Installer.php', "<?php if (! file_exists('vendor-installed')) { return redirect('/install'); }\n");

        $t = $this->treatments();
        $probe = $t->parseProbe($this->bash($t->probeCommand($this->root)));

        $this->assertSame($this->root, $probe['root']);
        $this->assertContains('routes/web.php', $probe['files']);
        $this->assertContains('app/Http/Middleware/CheckInstalled.php', $probe['files']);
        $this->assertNotContains('vendor/acme/pkg/Installer.php', $probe['files'], 'vendor code is never the app\'s own check');
        $this->assertSame('APP_INSTALLED', $probe['config_env']['app.installed']);

        $plan = $t->plan($probe);
        $this->assertSame(['storage/installed'], $plan['markers'], 'the .env existence check is not an installed marker');
        $this->assertSame(['APP_INSTALLED'], $plan['env_keys']);

        $output = $this->bash($t->applyMarkersCommand($this->root, $plan['markers']));
        $this->assertStringContainsString('CREATED=storage/installed', $output);
        $this->assertFileExists($this->root.'/storage/installed');
        $this->assertStringContainsString('installed ', (string) file_get_contents($this->root.'/storage/installed'));

        $again = $this->bash($t->applyMarkersCommand($this->root, $plan['markers']));
        $this->assertStringContainsString('PRESENT=storage/installed', $again);
    }

    #[Test]
    public function marker_paths_resolve_from_the_helpers_apps_use(): void
    {
        $t = $this->treatments();

        $this->assertSame('storage/installed', $t->resolveMarkerPath("storage_path('installed')", 'routes', false));
        $this->assertSame('install/installed.txt', $t->resolveMarkerPath("base_path('install/installed.txt')", 'app/Http', false));
        $this->assertSame('public/.installed', $t->resolveMarkerPath("public_path('.installed')", 'routes', false));
        $this->assertSame('includes/installed.lock', $t->resolveMarkerPath("__DIR__ . '/installed.lock'", 'includes', false));
        $this->assertSame('installed.lock', $t->resolveMarkerPath("__DIR__.'/installed.lock'", '.', false));
        $this->assertSame('storage/app/installed', $t->resolveMarkerPath("'installed'", 'routes', true));
        $this->assertSame('application/config/installed.php', $t->resolveMarkerPath("'installed.php'", 'application/config', false));
        $this->assertNull($t->resolveMarkerPath("'/etc/installed'", 'routes', false), 'absolute paths are not ours to create');
        $this->assertNull($t->resolveMarkerPath("base_path('../installed')", 'routes', false), 'no climbing out of the app');
        $this->assertNull($t->resolveMarkerPath('$this->markerPath()', 'routes', false), 'a computed path is left to the operator');
    }

    #[Test]
    public function an_app_that_checks_nothing_recognisable_gets_no_plan_but_keeps_the_evidence(): void
    {
        File::put($this->root.'/routes/web.php', "<?php\nif (! Schema::hasTable('settings')) { return redirect('/install'); }\n");
        $t = $this->treatments();

        $plan = $t->plan($t->parseProbe($this->bash($t->probeCommand($this->root))));

        $this->assertSame([], $plan['markers']);
        $this->assertSame([], $plan['env_keys']);
        $this->assertNotEmpty($plan['evidence']);
        $this->assertStringContainsString('hasTable', $plan['evidence'][0]);
    }

    #[Test]
    public function an_install_middleware_that_tests_the_env_file_is_recognised_even_without_the_word_install_on_that_line(): void
    {
        File::put($this->root.'/app/Http/Middleware/IsInstalled.php', <<<'PHP'
<?php
class IsInstalled {
    public function handle($request, $next) {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            return redirect(url('/').'/install');
        }
        return $next($request);
    }
}
PHP);
        $t = $this->treatments();
        $probe = $t->parseProbe($this->bash($t->probeCommand($this->root)));

        $this->assertFalse($probe['env_present'], 'the fixture has no .env');
        $plan = $t->plan($probe);
        $this->assertTrue($plan['needs_env_file']);
        $this->assertSame([], $plan['markers']);
        $this->assertSame([], $plan['env_keys']);
        $this->assertStringContainsString("base_path('.env')", implode("\n", $plan['evidence']));

        File::put($this->root.'/.env', "APP_KEY=x\n");
        $this->assertTrue($t->parseProbe($this->bash($t->probeCommand($this->root)))['env_present']);
    }

    #[Test]
    public function a_missing_env_is_copied_back_from_the_incident_that_archived_it_and_never_over_an_existing_one(): void
    {
        $t = $this->treatments();
        File::ensureDirectoryExists($this->root.'/incidents/20260916-165225-he2eap/files');
        File::put($this->root.'/incidents/20260916-165225-he2eap/files/.env', "APP_KEY=base64:abc\nDB_DATABASE=khonamart\n");
        $source = $this->root.'/incidents/20260916-165225-he2eap/files/.env';
        $target = $this->root.'/.env';

        $this->assertStringContainsString('RESTORED=.env', $this->bash($t->restoreEnvFromIncidentCommand($source, $target)));
        $this->assertSame("APP_KEY=base64:abc\nDB_DATABASE=khonamart\n", (string) file_get_contents($target));
        $this->assertFileExists($source, 'the incident keeps its copy');

        File::put($target, "APP_KEY=live\n");
        $this->assertStringContainsString('PRESENT=.env', $this->bash($t->restoreEnvFromIncidentCommand($source, $target)));
        $this->assertSame("APP_KEY=live\n", (string) file_get_contents($target));

        $this->assertStringContainsString('MISSING=source', $this->bash($t->restoreEnvFromIncidentCommand($this->root.'/incidents/nope/files/.env', $this->root.'/other.env')));
    }
}
