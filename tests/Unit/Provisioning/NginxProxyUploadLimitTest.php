<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Services\Provisioning\NginxProxyService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NginxProxyUploadLimitTest extends TestCase
{
    #[Test]
    public function generated_vhost_includes_client_max_body_size(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $nginx = new NginxProxyService;
        $this->assertSame('100M', $nginx->clientMaxBodySize());

        $domain = new ContainerDomain(['domain' => 'example.test', 'ssl_enabled' => false]);
        $deployment = new ContainerDeployment(['assigned_port' => 30001]);
        $deployment->setRelation('node', new Node(['ip_address' => '10.0.0.1']));
        $domain->setRelation('deployment', $deployment);

        $http = $nginx->generateConfig($domain, false);
        $this->assertStringContainsString('client_max_body_size 100M;', $http);

        $domain->ssl_enabled = true;
        $domain->ssl_certificate_path = '/etc/letsencrypt/live/example.test/fullchain.pem';
        $domain->ssl_key_path = '/etc/letsencrypt/live/example.test/privkey.pem';

        $https = $nginx->generateConfig($domain, true);
        $this->assertStringContainsString('listen 443 ssl', $https);
        $this->assertStringContainsString('client_max_body_size 100M;', $https);
    }
}
