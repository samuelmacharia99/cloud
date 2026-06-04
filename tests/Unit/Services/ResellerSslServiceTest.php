<?php

namespace Tests\Unit\Services;

use App\Services\ResellerSslService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerSslServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_certbot_failure_skips_boilerplate_and_returns_detail(): void
    {
        $output = <<<'TXT'
Saving debug log to /var/log/letsencrypt/letsencrypt.log
The following error was encountered:
Detail: 89.167.115.94: Invalid response from http://server.enthelotcloud.com/.well-known/acme-challenge/test: 404
TXT;

        $summary = app(ResellerSslService::class)->summarizeCertbotFailure($output);

        $this->assertStringNotContainsString('following error was encountered', strtolower($summary));
        $this->assertStringContainsString('404', $summary);
        $this->assertStringContainsString('acme-challenge', $summary);
    }

    public function test_summarize_certbot_failure_returns_tail_when_no_detail_line(): void
    {
        $output = "Line one\nLine two\nSomething went wrong entirely\n";

        $summary = app(ResellerSslService::class)->summarizeCertbotFailure($output);

        $this->assertStringContainsString('Something went wrong', $summary);
    }

    public function test_boilerplate_header_only_stdout_is_not_used_as_error(): void
    {
        $service = app(ResellerSslService::class);

        $output = "Saving debug log to /var/log/letsencrypt/letsencrypt.log\nThe following error was encountered:\n";

        $this->assertTrue($service->isBoilerplateSslMessage('The following error was encountered:'));

        $summary = $service->summarizeCertbotFailure($output);

        $this->assertStringNotContainsString('following error was encountered', strtolower($summary));

        $display = $service->resolveSslFailureDisplay([
            'status' => 'failed',
            'error' => 'The following error was encountered:',
            'last_output' => null,
        ]);

        $this->assertStringNotContainsString('following error was encountered', strtolower($display['error']));
    }

    public function test_resolve_display_reparses_stored_boilerplate_when_output_exists(): void
    {
        $service = app(ResellerSslService::class);

        $display = $service->resolveSslFailureDisplay([
            'status' => 'failed',
            'error' => 'The following error was encountered:',
            'last_output' => "The following error was encountered:\nDetail: challenge failed for server.example.com: 404",
        ]);

        $this->assertStringContainsString('404', $display['error']);
        $this->assertStringNotContainsString('following error was encountered', strtolower($display['error']));
    }

    public function test_build_ssl_provision_command_uses_provision_script_when_configured(): void
    {
        $script = base_path('scripts/reseller-ssl/provision.sh');
        $this->assertFileExists($script);

        config([
            'app.reseller_ssl_use_provision_script' => true,
            'app.reseller_ssl_provision_script' => $script,
            'app.reseller_ssl_certbot_sudo' => true,
        ]);

        $service = app(ResellerSslService::class);
        $command = $service->buildSslProvisionCommand('server.example.com', storage_path('app/ssl-test-logs'));

        $this->assertStringContainsString('sudo -n', $command);
        $this->assertStringContainsString('provision.sh', $command);
        $this->assertStringContainsString('--domain', $command);
        $this->assertStringContainsString('server.example.com', $command);
        $this->assertStringContainsString('--webroot', $command);
    }

    public function test_build_ssl_provision_command_falls_back_to_certbot_when_script_disabled(): void
    {
        config([
            'app.reseller_ssl_use_provision_script' => false,
            'app.reseller_ssl_certbot_sudo' => false,
        ]);

        $command = app(ResellerSslService::class)->buildSslProvisionCommand(
            'server.example.com',
            storage_path('app/ssl-test-logs'),
        );

        $this->assertStringContainsString('certonly', $command);
        $this->assertStringContainsString('--webroot-path', $command);
        $this->assertStringNotContainsString('provision.sh', $command);
    }

    public function test_resolve_certificate_paths_from_provision_script_output(): void
    {
        $service = app(ResellerSslService::class);

        $output = <<<'TXT'
[talksasa-ssl] SUCCESS: certificate issued for server.example.com
CERT_PATH=/etc/letsencrypt/live/server.example.com/fullchain.pem
KEY_PATH=/etc/letsencrypt/live/server.example.com/privkey.pem
TXT;

        $paths = $service->resolveCertificatePaths($output, 'server.example.com');

        $this->assertNotNull($paths);
        $this->assertSame('/etc/letsencrypt/live/server.example.com/fullchain.pem', $paths['cert_path']);
        $this->assertSame('/etc/letsencrypt/live/server.example.com/privkey.pem', $paths['key_path']);
    }

    public function test_map_provision_exit_detects_sudo_password_required(): void
    {
        $message = app(ResellerSslService::class)->mapProvisionExitToMessage(
            'sudo: a password is required',
        );

        $this->assertNotNull($message);
        $this->assertStringContainsString('install-host.sh', $message);
    }
}
