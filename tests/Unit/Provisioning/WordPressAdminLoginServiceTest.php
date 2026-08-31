<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\WordPressAdminLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressAdminLoginServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_detects_shared_plan_wordpress_via_effective_template(): void
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

        $this->assertTrue(
            (new WordPressAdminLoginService)->isWordPressContainer($service->fresh(['product.containerTemplate']))
        );
    }

    #[Test]
    public function it_detects_wordpress_container_services(): void
    {
        $template = new ContainerTemplate(['slug' => 'wordpress']);
        $product = new Product(['type' => 'container_hosting']);
        $product->setRelation('containerTemplate', $template);
        $service = new Service;
        $service->setRelation('product', $product);

        $login = new WordPressAdminLoginService;

        $this->assertTrue($login->isWordPressContainer($service));
    }

    #[Test]
    public function it_rejects_non_wordpress_services(): void
    {
        $template = new ContainerTemplate(['slug' => 'laravel']);
        $product = new Product(['type' => 'container_hosting']);
        $product->setRelation('containerTemplate', $template);
        $service = new Service;
        $service->setRelation('product', $product);

        $this->assertFalse((new WordPressAdminLoginService)->isWordPressContainer($service));
    }

    #[Test]
    public function mu_plugin_contains_sso_hook(): void
    {
        $contents = (new WordPressAdminLoginService)->muPluginContents();

        $this->assertStringContainsString('talksasa_admin_sso', $contents);
        $this->assertStringContainsString('wp_set_auth_cookie', $contents);
        $this->assertStringContainsString('mu-plugins/.talksasa-admin-sso.json', $contents);
        $this->assertStringContainsString('admin_url(', $contents);
        $this->assertStringContainsString('login_init', $contents);
        $this->assertStringContainsString('TALKASA_SSO_V4', $contents);
    }

    #[Test]
    public function it_parses_admin_id_from_noisy_container_output(): void
    {
        $login = new WordPressAdminLoginService;

        $noisy = "PHP Warning: session_start(): Failed...\nTALKASA_WP_ADMIN_ID=7\n";
        $this->assertSame(7, $login->parseAdministratorIdFromOutput($noisy));
        $this->assertSame(0, $login->parseAdministratorIdFromOutput("TALKASA_WP_ADMIN_ID=0\n"));
        $this->assertSame(0, $login->parseAdministratorIdFromOutput('no marker'));
    }

    #[Test]
    public function it_parses_diagnostics_reported_with_a_zero_admin_id(): void
    {
        $login = new WordPressAdminLoginService;

        $output = 'PHP Notice: something noisy'."\n"
            .'TALKASA_WP_ADMIN_DIAG={"multisite":0,"users":4,"cap_rows":4,"meta_key":"wp_capabilities","roles":["subscriber"]}'."\n"
            .'TALKASA_WP_ADMIN_ID=0';

        $diagnostics = $login->parseDiagnosticsFromOutput($output);

        $this->assertSame(4, $diagnostics['users']);
        $this->assertSame('wp_capabilities', $diagnostics['meta_key']);
        $this->assertSame(['subscriber'], $diagnostics['roles']);
    }

    #[Test]
    public function it_returns_null_diagnostics_when_the_probe_never_answered(): void
    {
        $login = new WordPressAdminLoginService;

        $this->assertNull($login->parseDiagnosticsFromOutput(''));
        $this->assertNull($login->parseDiagnosticsFromOutput('TALKASA_WP_ADMIN_DIAG=not-json'));
    }

    #[Test]
    public function no_administrator_message_reflects_what_the_site_actually_has(): void
    {
        $login = new WordPressAdminLoginService;

        $this->assertStringContainsString(
            'no WordPress users at all',
            $login->noAdministratorMessage(['users' => 0])
        );

        $withUsers = $login->noAdministratorMessage(['users' => 4]);
        $this->assertStringContainsString('4 users', $withUsers);
        $this->assertStringContainsString('none of them holds the administrator role', $withUsers);

        $this->assertStringContainsString(
            'at least one administrator',
            $login->noAdministratorMessage(null)
        );
    }

    #[Test]
    public function admin_probe_falls_back_through_every_strategy(): void
    {
        $script = (new WordPressAdminLoginService)->administratorProbeScript('siteowner', 'owner@example.com');

        $this->assertStringContainsString("\$preferred = 'siteowner';", $script);
        $this->assertStringContainsString("\$preferredEmail = 'owner@example.com';", $script);
        $this->assertStringContainsString("'role' => 'administrator'", $script);
        $this->assertStringContainsString("'capability' => 'manage_options'", $script);
        $this->assertStringContainsString('get_super_admins', $script);
        $this->assertStringContainsString('TALKASA_WP_ADMIN_DIAG=', $script);
        $this->assertStringContainsString("echo 'TALKASA_WP_ADMIN_ID=0';", $script);
    }

    #[Test]
    public function admin_probe_sanitises_untrusted_env_values(): void
    {
        $login = new WordPressAdminLoginService;

        $script = $login->administratorProbeScript("ad'min; rm -rf /", 'not-an-email');

        $this->assertStringContainsString("\$preferred = 'adminrm-rf';", $script);
        $this->assertStringContainsString("\$preferredEmail = '';", $script);
        $this->assertStringNotContainsString("ad'min", $script);
        $this->assertStringNotContainsString('rm -rf', $script);

        $this->assertStringContainsString(
            "\$preferred = 'admin';",
            $login->administratorProbeScript('   ')
        );
    }

    #[Test]
    public function admin_probe_is_valid_php(): void
    {
        $script = (new WordPressAdminLoginService)->administratorProbeScript('admin', 'admin@example.com');

        $file = tempnam(sys_get_temp_dir(), 'wp-probe').'.php';
        file_put_contents($file, '<?php '.$script);

        exec('php -l '.escapeshellarg($file).' 2>&1', $lintOutput, $lintStatus);
        @unlink($file);

        $this->assertSame(0, $lintStatus, implode("\n", $lintOutput));
    }
}
