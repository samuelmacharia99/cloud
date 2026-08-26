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
        $this->assertStringContainsString('proxy_buffer_size 32k;', $https);
        $this->assertTrue($nginx->vhostIsCurrent($https));
    }

    #[Test]
    public function generated_vhost_streams_uploads_over_http_1_1(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $nginx = new NginxProxyService;
        $domain = new ContainerDomain(['domain' => 'example.test', 'ssl_enabled' => false]);
        $deployment = new ContainerDeployment(['assigned_port' => 30001]);
        $deployment->setRelation('node', new Node(['ip_address' => '10.0.0.1']));
        $domain->setRelation('deployment', $deployment);

        $http = $nginx->generateConfig($domain, false);

        // HTTP/1.0 upstreams with request buffering off stall large media uploads.
        $this->assertStringContainsString('proxy_http_version 1.1;', $http);
        $this->assertStringNotContainsString('proxy_request_buffering off', $http);
        $this->assertTrue($nginx->vhostIsCurrent($http));
        $this->assertStringContainsString('proxy_buffer_size 32k;', $http);
        $this->assertStringContainsString('proxy_buffers 8 32k;', $http);
        $this->assertStringContainsString('proxy_busy_buffers_size 64k;', $http);
    }

    #[Test]
    public function vhosts_from_older_builds_are_not_treated_as_current(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $legacy = <<<'CONF'
server {
    listen 80;
    server_name example.test;
    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:30001;
        proxy_request_buffering off;
    }
}
CONF;

        $this->assertFalse((new NginxProxyService)->vhostIsCurrent($legacy));
    }

    #[Test]
    public function v2_vhosts_without_header_buffers_are_not_current(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $v2 = <<<'CONF'
# talksasa-vhost v2
server {
    listen 80;
    server_name example.test;
    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:30001;
        proxy_http_version 1.1;
    }
}
CONF;

        $this->assertFalse((new NginxProxyService)->vhostIsCurrent($v2));
    }
}
