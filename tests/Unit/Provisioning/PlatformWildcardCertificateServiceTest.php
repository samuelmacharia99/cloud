<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Services\Provisioning\NginxProxyService;
use App\Services\Provisioning\PlatformWildcardCertificateService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One wildcard per node, issued over DNS-01, because fifty certificates a
 * week per registered domain is what Let's Encrypt allows and the rollout
 * alone would ask for more than that.
 */
class PlatformWildcardCertificateServiceTest extends TestCase
{
    #[Test]
    public function a_valid_certificate_on_the_node_is_left_alone(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command): ?string => str_contains($command, 'openssl x509 -checkend') ? 'valid' : null);

        $paths = (new PlatformWildcardCertificateService)->ensureOnNode($ssh, $this->node(), 'apps.example.com', 'dns-token', 'ops@example.com');

        $this->assertSame('/etc/letsencrypt/live/apps.example.com/fullchain.pem', $paths['cert']);
        $this->assertSame('/etc/letsencrypt/live/apps.example.com/privkey.pem', $paths['key']);
        $this->assertCount(1, $commands);
        $this->assertStringNotContainsString('certbot', implode("\n", $commands));
    }

    #[Test]
    public function a_missing_certificate_is_issued_with_the_dns_plugin_and_a_private_credentials_file(): void
    {
        $commands = [];
        $uploads = [];
        $checks = 0;
        $ssh = $this->ssh($commands, function (string $command) use (&$checks): ?string {
            if (str_contains($command, 'openssl x509 -checkend')) {
                // Missing before certbot runs, valid afterwards.
                return ++$checks <= 2 ? 'missing' : 'valid';
            }

            return null;
        }, $uploads);

        $paths = (new PlatformWildcardCertificateService)->ensureOnNode($ssh, $this->node(), 'Apps.Example.com', 'dns-token', 'ops@example.com');

        $this->assertSame('/etc/letsencrypt/live/apps.example.com/fullchain.pem', $paths['cert']);
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('import certbot_dns_cloudflare', $joined);
        $this->assertStringContainsString('apt-get install -y -qq --no-install-recommends certbot python3-certbot-dns-cloudflare', $joined);
        $this->assertStringContainsString("chmod 600 '/etc/letsencrypt/talksasa-apps-cloudflare.ini'", $joined);
        $this->assertStringContainsString("certbot certonly --non-interactive --agree-tos --email 'ops@example.com' --dns-cloudflare --dns-cloudflare-credentials '/etc/letsencrypt/talksasa-apps-cloudflare.ini' --dns-cloudflare-propagation-seconds 30 --cert-name 'apps.example.com' -d 'apps.example.com' -d '*.apps.example.com'", $joined);
        $this->assertSame([['/etc/letsencrypt/talksasa-apps-cloudflare.ini', "dns_cloudflare_api_token = dns-token\n"]], $uploads);
    }

    #[Test]
    public function an_issuance_that_leaves_no_certificate_behind_is_an_error_with_certbots_words_in_it(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands, function (string $command): ?string {
            if (str_contains($command, 'openssl x509 -checkend')) {
                return 'missing';
            }
            if (str_contains($command, 'certbot certonly')) {
                return 'Error determining zone_id: 6003 Invalid request headers';
            }

            return null;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid request headers');

        (new PlatformWildcardCertificateService)->ensureOnNode($ssh, $this->node(), 'apps.example.com', 'dns-token', 'ops@example.com');
    }

    #[Test]
    public function a_bad_zone_or_token_is_refused_before_anything_reaches_the_node(): void
    {
        $commands = [];
        $service = new PlatformWildcardCertificateService;

        try {
            $service->certificatePaths('not a zone');
            $this->fail('Expected the zone to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->ensureOnNode(
                $this->ssh($commands, fn (string $command): ?string => str_contains($command, 'checkend') ? 'missing' : null),
                $this->node(),
                'apps.example.com',
                "bad token\n",
                'ops@example.com'
            );
            $this->fail('Expected the token to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('malformed', $e->getMessage());
        }

        $this->assertStringNotContainsString('certbot certonly', implode("\n", $commands));
    }

    #[Test]
    public function renewal_reloads_nginx_so_the_vhosts_pick_up_the_new_files(): void
    {
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command): ?string => str_contains($command, 'certbot renew') ? 'Cert not yet due for renewal' : null);
        $node = $this->node();
        $nginx = Mockery::mock(NginxProxyService::class);
        $nginx->shouldReceive('reload')->once()->with($ssh, $node);

        (new PlatformWildcardCertificateService)->renewOnNode($ssh, $node, 'apps.example.com', $nginx);

        $this->assertStringContainsString("certbot renew --cert-name 'apps.example.com' --non-interactive", $commands[0]);
    }

    private function node(): Node
    {
        return new Node(['hostname' => 'node-1.example.com']);
    }

    /**
     * @param  list<string>  $commands
     * @param  list<array{0: string, 1: string}>  $uploads
     */
    private function ssh(array &$commands, ?callable $answer = null, array &$uploads = []): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $answer): string {
            $commands[] = $command;

            return (string) ($answer ? $answer($command) : '');
        });
        $ssh->shouldReceive('upload')->andReturnUsing(function (string $content, string $path) use (&$uploads): void {
            $uploads[] = [$path, $content];
        });

        return $ssh;
    }
}
