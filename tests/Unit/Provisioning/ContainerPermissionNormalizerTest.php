<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerPermissionNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerPermissionNormalizerTest extends TestCase
{
    #[Test]
    public function the_strip_command_drops_dangerous_bits_and_locks_secrets_without_touching_dependencies(): void
    {
        $normalizer = app(ContainerPermissionNormalizer::class);

        $wordpress = $normalizer->stripDangerousBitsCommand('/opt/talksasa/containers/shop-wordpress/app', true);
        $this->assertStringContainsString('-perm -o+w -exec chmod o-w {} +', $wordpress);
        $this->assertStringContainsString('-perm -4000 -o -perm -2000', $wordpress);
        $this->assertStringContainsString('wp-content/uploads', $wordpress);
        $this->assertStringContainsString('chmod 640', $wordpress);
        $this->assertStringContainsString("-path '/opt/talksasa/containers/shop-wordpress/app'/vendor", $wordpress);
        $this->assertStringContainsString("chown -R 33:33 '/opt/talksasa/containers/shop-wordpress/app'/wp-content", $wordpress);
        $this->assertSame(0, (int) trim((string) shell_exec('bash -n -c '.escapeshellarg($wordpress).' >/dev/null 2>&1; echo $?')), 'the command parses as bash');

        $laravel = $normalizer->stripDangerousBitsCommand('/opt/talksasa/containers/api-laravel/app', false);
        $this->assertStringContainsString('storage/app/public', $laravel);
        $this->assertStringNotContainsString('wp-content', $laravel);
        $this->assertSame(0, (int) trim((string) shell_exec('bash -n -c '.escapeshellarg($laravel).' >/dev/null 2>&1; echo $?')));
    }

    #[Test]
    public function the_strip_command_actually_fixes_a_tree(): void
    {
        $root = sys_get_temp_dir().'/talksasa-perm-'.uniqid();
        mkdir($root.'/wp-content/uploads/2024', 0777, true);
        mkdir($root.'/vendor/pkg', 0777, true);
        file_put_contents($root.'/wp-config.php', '<?php');
        file_put_contents($root.'/wp-content/uploads/2024/run.jpg', 'x');
        file_put_contents($root.'/wp-content/plugins.php', 'x');
        file_put_contents($root.'/vendor/pkg/loose.php', 'x');
        chmod($root.'/wp-config.php', 0644);
        chmod($root.'/wp-content/uploads/2024', 0777);
        chmod($root.'/wp-content/uploads/2024/run.jpg', 0755);
        chmod($root.'/wp-content/plugins.php', 04777);
        chmod($root.'/vendor/pkg/loose.php', 0777);

        $command = app(ContainerPermissionNormalizer::class)->stripDangerousBitsCommand($root, true);
        // chown to 33 needs root; everything else runs as the test user.
        $command = str_replace('chown 33:33 "$f" && ', '', str_replace(' && chown -R 33:33 ', ' && true ', $command));
        shell_exec('bash -c '.escapeshellarg($command).' 2>/dev/null');
        clearstatcache();

        $this->assertSame('0640', substr(sprintf('%o', fileperms($root.'/wp-config.php')), -4));
        $this->assertSame('0775', substr(sprintf('%o', fileperms($root.'/wp-content/uploads/2024')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($root.'/wp-content/uploads/2024/run.jpg')), -4));
        $this->assertSame('0775', substr(sprintf('%o', fileperms($root.'/wp-content/plugins.php')), -4), 'setuid and world-write gone, exec kept outside uploads');
        $this->assertSame('0777', substr(sprintf('%o', fileperms($root.'/vendor/pkg/loose.php')), -4), 'vendor is left alone');

        shell_exec('rm -rf '.escapeshellarg($root));
    }
}
