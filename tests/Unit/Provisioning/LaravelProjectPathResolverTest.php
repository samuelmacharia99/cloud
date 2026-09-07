<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\LaravelProjectPathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LaravelProjectPathResolverTest extends TestCase
{
    #[Test]
    public function it_maps_container_and_host_project_roots(): void
    {
        $resolver = new LaravelProjectPathResolver;

        $this->assertSame('/app', $resolver->containerProjectRoot(''));
        $this->assertSame('/app/core', $resolver->containerProjectRoot('core'));
        $this->assertSame('/opt/talksasa/containers/demo/app', $resolver->hostProjectRoot('/opt/talksasa/containers/demo/app', ''));
        $this->assertSame(
            '/opt/talksasa/containers/demo/app/core',
            $resolver->hostProjectRoot('/opt/talksasa/containers/demo/app', 'core')
        );
    }

    #[Test]
    public function it_reads_document_root_from_service_meta(): void
    {
        $resolver = new LaravelProjectPathResolver;
        $service = new Service([
            'service_meta' => [
                'laravel_project_root' => 'core',
                'laravel_document_root' => '/app',
            ],
        ]);

        $this->assertSame('/app/core', $resolver->projectRootFromServiceMeta($service));
        $this->assertSame('/app', $resolver->documentRootFromServiceMeta($service));
    }

    #[Test]
    public function it_prefers_laravel_public_then_directadmin_public_html(): void
    {
        $resolver = new LaravelProjectPathResolver;

        $this->assertSame(['public', 'public_html'], $resolver->webRootRelativeCandidates());
        $this->assertSame(
            ['public', 'public_html', 'web', 'htdocs', 'html', 'www'],
            $resolver->phpWebRootRelativeCandidates()
        );
    }

    #[Test]
    public function php_document_root_command_prefers_public_html_over_root_index(): void
    {
        $tmp = sys_get_temp_dir().'/php-docroot-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public_html', 0777, true);
        file_put_contents($tmp.'/index.php', '<?php echo "root";');
        file_put_contents($tmp.'/public_html/index.php', '<?php echo "html";');

        try {
            $cmd = (new LaravelProjectPathResolver)->phpDocumentRootCommand($tmp);
            exec('sh -lc '.escapeshellarg($cmd), $output, $code);
            $this->assertSame(0, $code);
            $this->assertSame('/app/public_html', trim(implode("\n", $output)));
        } finally {
            @unlink($tmp.'/public_html/index.php');
            @rmdir($tmp.'/public_html');
            @unlink($tmp.'/index.php');
            @rmdir($tmp);
        }
    }

    #[Test]
    public function php_document_root_command_skips_tiny_public_stub_when_root_index_is_the_site(): void
    {
        $tmp = sys_get_temp_dir().'/php-docroot-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public', 0777, true);
        file_put_contents($tmp.'/index.php', str_repeat("<?php echo 'site';\n", 40));
        file_put_contents($tmp.'/public/index.php', '<?php echo "stub";');

        try {
            $cmd = (new LaravelProjectPathResolver)->phpDocumentRootCommand($tmp);
            $output = [];
            exec('sh -lc '.escapeshellarg($cmd), $output, $code);
            $this->assertSame(0, $code);
            $this->assertSame('/app', trim(implode("\n", $output)));
        } finally {
            @unlink($tmp.'/public/index.php');
            @rmdir($tmp.'/public');
            @unlink($tmp.'/index.php');
            @rmdir($tmp);
        }
    }
}
