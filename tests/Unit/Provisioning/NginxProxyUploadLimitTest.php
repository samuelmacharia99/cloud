<?php

namespace Tests\Unit\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
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
        $this->assertStringContainsString('proxy_buffer_size 128k;', $https);
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
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $http);
        $this->assertStringContainsString('proxy_set_header Connection "upgrade";', $http);
        $this->assertStringNotContainsString('proxy_set_header Connection "";', $http);
        $this->assertStringNotContainsString('proxy_request_buffering off', $http);
        $this->assertTrue($nginx->vhostIsCurrent($http));
        $this->assertStringContainsString('proxy_buffer_size 128k;', $http);
        $this->assertStringContainsString('proxy_buffers 4 256k;', $http);
        $this->assertStringContainsString('proxy_busy_buffers_size 256k;', $http);
        $this->assertStringContainsString('error_page 502 503 504 /unavailable.html;', $http);
        $this->assertStringContainsString('# talksasa-edge-proxy', $http);
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

    #[Test]
    public function v4_vhosts_without_websocket_upgrade_are_not_current(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $v4 = <<<'CONF'
# talksasa-vhost v4
server {
    listen 80;
    server_name example.test;
    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:30001;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffer_size 128k;
    }
}
CONF;

        $this->assertFalse((new NginxProxyService)->vhostIsCurrent($v4));
    }

    #[Test]
    public function v5_vhosts_without_branded_error_pages_are_not_current(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $v5 = <<<'CONF'
# talksasa-vhost v5
server {
    listen 80;
    server_name example.test;
    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:30001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_buffer_size 128k;
    }
}
CONF;

        $this->assertFalse((new NginxProxyService)->vhostIsCurrent($v5));
    }

    #[Test]
    public function suspended_vhost_returns_503_instead_of_proxying(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $nginx = new NginxProxyService;
        $domain = new ContainerDomain(['domain' => 'paused.example.test', 'ssl_enabled' => false]);
        $deployment = new ContainerDeployment(['assigned_port' => 30001]);
        $deployment->setRelation('node', new Node(['ip_address' => '10.0.0.1']));
        $domain->setRelation('deployment', $deployment);

        $http = $nginx->generateConfig($domain, false, true);

        $this->assertStringContainsString('# talksasa-edge-suspended', $http);
        $this->assertStringContainsString('return 503;', $http);
        $this->assertStringContainsString('/suspended.html', $http);
        $this->assertStringContainsString(NginxProxyService::EDGE_PAGES_DIR, $http);
        $this->assertStringNotContainsString('proxy_pass', $http);
        $this->assertTrue($nginx->vhostIsCurrent($http, true));
        $this->assertFalse($nginx->vhostIsCurrent($http, false));
    }

    #[Test]
    public function generate_config_parks_when_service_is_suspended(): void
    {
        $nginx = new NginxProxyService;
        $domain = new ContainerDomain(['domain' => 'billing.example.test', 'ssl_enabled' => false]);
        $deployment = new ContainerDeployment(['assigned_port' => 30001]);
        $deployment->setRelation('node', new Node(['ip_address' => '10.0.0.1']));
        $deployment->setRelation('service', new Service(['status' => ServiceStatus::Suspended]));
        $domain->setRelation('deployment', $deployment);

        $http = $nginx->generateConfig($domain, false);

        $this->assertTrue($nginx->domainShouldServeSuspendedPage($domain));
        $this->assertStringContainsString('return 503;', $http);
        $this->assertStringNotContainsString('proxy_pass', $http);
    }

    #[Test]
    public function parked_vhost_on_an_active_service_is_not_current(): void
    {
        config(['security.container_file_upload.max_size_mb' => 100]);

        $nginx = new NginxProxyService;
        $parked = $nginx->generateConfig(
            $this->exampleDomain(),
            false,
            true
        );

        $this->assertFalse($nginx->vhostIsCurrent($parked, false));
    }

    #[Test]
    public function edge_pages_include_portal_login_and_no_placeholder_copy(): void
    {
        config(['app.name' => 'Talksasa Cloud']);

        $nginx = new NginxProxyService;
        $suspended = $nginx->suspendedPageHtml();
        $unavailable = $nginx->unavailablePageHtml();
        $login = url('/login');

        $this->assertStringContainsString('This website is paused', $suspended);
        $this->assertStringContainsString($login, $suspended);
        $this->assertStringContainsString('Talksasa Cloud', $suspended);
        $this->assertStringContainsString('sign in to see why', $suspended);
        $this->assertStringNotContainsString('TODO', $suspended);
        $this->assertStringNotContainsString('lorem', strtolower($suspended));

        $this->assertStringContainsString('temporarily unavailable', $unavailable);
        $this->assertStringContainsString($login, $unavailable);
        $this->assertStringNotContainsString('502 Bad Gateway', $unavailable);
    }

    private function exampleDomain(): ContainerDomain
    {
        $domain = new ContainerDomain(['domain' => 'example.test', 'ssl_enabled' => false]);
        $deployment = new ContainerDeployment(['assigned_port' => 30001]);
        $deployment->setRelation('node', new Node(['ip_address' => '10.0.0.1']));
        $domain->setRelation('deployment', $deployment);

        return $domain;
    }
}
