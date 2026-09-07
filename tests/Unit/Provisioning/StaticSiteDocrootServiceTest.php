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
        $this->assertStringContainsString('has_real_html', $cmd);
        $this->assertStringContainsString('Welcome to Talksasa Cloud', $cmd);
        $this->assertStringContainsString('tar cf -', $cmd);
        $this->assertSame('public', (new StaticSiteDocrootService)->nestedWebRootNames()[
            array_key_last((new StaticSiteDocrootService)->nestedWebRootNames())
        ]);
    }

    #[Test]
    public function flatten_command_strips_the_talksasa_placeholder_and_lifts_public_html(): void
    {
        $tmp = sys_get_temp_dir().'/static-docroot-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public', 0777, true);
        mkdir($tmp.'/public_html', 0777, true);
        file_put_contents($tmp.'/index.html', '<h1>Welcome to Talksasa Cloud</h1>');
        file_put_contents($tmp.'/public/index.html', '<h1>Welcome to Talksasa Cloud</h1>');
        file_put_contents($tmp.'/public_html/index.html', '<h1>Roadtrip</h1>');

        try {
            $cmd = (new StaticSiteDocrootService)->flattenWebRootCommand($tmp);
            exec('sh -lc '.escapeshellarg($cmd), $output, $code);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('Roadtrip', (string) file_get_contents($tmp.'/index.html'));
            $this->assertStringNotContainsString(
                'Welcome to Talksasa Cloud',
                (string) file_get_contents($tmp.'/index.html')
            );
            $this->assertSame('html', $this->webRootKind($tmp));
        } finally {
            $this->removeDirectory($tmp);
        }
    }

    #[Test]
    public function flatten_command_does_not_hoist_public_when_it_is_only_the_placeholder(): void
    {
        $tmp = sys_get_temp_dir().'/static-docroot-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public', 0777, true);
        file_put_contents($tmp.'/public/index.html', '<h1>Welcome to Talksasa Cloud</h1>');
        file_put_contents($tmp.'/index.php', '<?php echo "app";');

        try {
            $cmd = (new StaticSiteDocrootService)->flattenWebRootCommand($tmp);
            exec('sh -lc '.escapeshellarg($cmd), $output, $code);

            $this->assertSame(0, $code);
            $this->assertFileDoesNotExist($tmp.'/index.html');
            $this->assertFileExists($tmp.'/index.php');
            $this->assertSame('php', $this->webRootKind($tmp));
        } finally {
            $this->removeDirectory($tmp);
        }
    }

    private function webRootKind(string $path): string
    {
        $cmd = (new StaticSiteDocrootService)->webRootKindCommand($path);
        exec('sh -lc '.escapeshellarg($cmd), $output, $code);
        $this->assertSame(0, $code);

        return trim(implode("\n", $output));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
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
