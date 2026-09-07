<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\StaticSiteDocrootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaticSiteDocrootServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function flatten_command_lifts_nested_public_html_when_root_has_no_index(): void
    {
        $cmd = (new StaticSiteDocrootService)
            ->flattenWebRootCommand('/opt/talksasa/containers/user-483-service-426-static-site/app');

        $this->assertStringContainsString('public_html', $cmd);
        $this->assertStringContainsString('dist', $cmd);
        $this->assertStringContainsString('has_html', $cmd);
        $this->assertStringContainsString('tar cf -', $cmd);
    }

    #[Test]
    public function nginx_config_denies_dotfiles_and_falls_back_to_index_html(): void
    {
        $conf = (new StaticSiteDocrootService)->nginxConfigContents();

        $this->assertStringContainsString('root /usr/share/nginx/html;', $conf);
        $this->assertStringContainsString('location ~ /\\.', $conf);
        $this->assertStringContainsString('deny all;', $conf);
        $this->assertStringContainsString('try_files $uri $uri/ /index.html;', $conf);
    }

    #[Test]
    public function it_mounts_nginx_config_on_the_official_static_site_compose(): void
    {
        $yaml = <<<'YAML'
services:
  user-483-service-426-static-site:
    image: nginx:alpine
    container_name: user-483-service-426-static-site
    volumes:
      - /opt/talksasa/containers/user-483-service-426-static-site/app:/usr/share/nginx/html
YAML;

        $patched = (new StaticSiteDocrootService)
            ->patchComposeNginxConfigMount($yaml, 'user-483-service-426-static-site');

        $this->assertStringContainsString('nginx-static.conf:/etc/nginx/conf.d/default.conf:ro', $patched);
        $this->assertSame(
            $patched,
            (new StaticSiteDocrootService)->patchComposeNginxConfigMount(
                $patched,
                'user-483-service-426-static-site'
            )
        );
    }
}
