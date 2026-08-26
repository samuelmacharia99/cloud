<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerSslErrorPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerSslErrorPresenterTest extends TestCase
{
    private ContainerSslErrorPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new ContainerSslErrorPresenter;
    }

    #[Test]
    public function it_explains_acme_404_when_dns_points_elsewhere(): void
    {
        $details = <<<'TXT'
SSH command failed: certbot certonly --nginx -d 'tajmaal.co.ke' --non-interactive --agree-tos --email 'admin@talksasa.cloud' --redirect 2>&1
Error: Command exited with status 1
Output: Saving debug log to /var/log/letsencrypt/letsencrypt.log
Requesting a certificate for tajmaal.co.ke
Certbot failed to authenticate some domains (authenticator: nginx).
The Certificate Authority reported these problems:
Domain: tajmaal.co.ke
Type: unauthorized
Detail: 88.99.104.138: Invalid response from http://tajmaal.co.ke/.well-known/acme-challenge/cfPSn39IORKgPEbozyT1kJnoOFVwTQ8nv2RObidSz_c: 404
Hint: The Certificate Authority failed to verify the temporary nginx configuration changes made by Certbot.
TXT;

        $error = $this->presenter->explainSsl($details, 'tajmaal.co.ke', '10.20.30.40');

        $this->assertSame('Let’s Encrypt could not verify tajmaal.co.ke.', $error['title']);
        $this->assertStringContainsString('88.99.104.138', $error['guidance']);
        $this->assertStringContainsString('10.20.30.40', $error['guidance']);
        $this->assertStringContainsString('retry SSL', $error['guidance']);
        $this->assertStringNotContainsString('SSH command failed', $error['title']);
        $this->assertSame($details, $error['details']);
    }

    #[Test]
    public function it_explains_acme_404_when_dns_already_points_here(): void
    {
        $details = 'Type: unauthorized Detail: 10.20.30.40: Invalid response from http://app.example.com/.well-known/acme-challenge/token: 404';

        $error = $this->presenter->explainSsl($details, 'app.example.com', '10.20.30.40');

        $this->assertSame('Let’s Encrypt could not verify app.example.com.', $error['title']);
        $this->assertStringContainsString('DNS points here', $error['guidance']);
        $this->assertStringContainsString('404', $error['guidance']);
    }

    #[Test]
    public function it_explains_rate_limits(): void
    {
        $error = $this->presenter->explainSsl(
            'too many certificates already issued for example.com: rateLimited',
            'example.com',
            '1.2.3.4',
        );

        $this->assertSame('Let’s Encrypt rate limit reached for example.com.', $error['title']);
        $this->assertStringContainsString('Wait until the rate limit resets', $error['guidance']);
    }

    #[Test]
    public function flash_message_omits_raw_certbot_output(): void
    {
        $presented = $this->presenter->explainSsl(
            "SSH command failed: certbot certonly\nError: Command exited with status 1\nOutput: Type: unauthorized",
            'tajmaal.co.ke',
            '1.2.3.4',
        );

        $flash = $this->presenter->flashMessage($presented);

        $this->assertStringContainsString('could not verify tajmaal.co.ke', strtolower($flash));
        $this->assertStringNotContainsString('SSH command failed', $flash);
        $this->assertStringNotContainsString('Command exited with status', $flash);
    }
}
