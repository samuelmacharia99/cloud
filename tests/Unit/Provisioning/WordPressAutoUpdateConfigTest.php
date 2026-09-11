<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\WordPressContainerHardeningService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing updated WordPress after install, so a site kept whatever core version
 * it was born with. Core minor releases are what carry the security fixes, and
 * WordPress ships its own updater; it just has to be switched on.
 */
class WordPressAutoUpdateConfigTest extends TestCase
{
    #[Test]
    public function a_fresh_site_is_told_to_take_minor_core_updates(): void
    {
        $script = $this->hardeningScript();

        $this->assertStringContainsString('WP_AUTO_UPDATE_CORE', $script);
        $this->assertStringContainsString("'minor'", $script);
    }

    #[Test]
    public function an_already_hardened_site_has_the_constant_back_filled(): void
    {
        // Sites hardened by an older deploy already carry the proxy marker, so
        // the snippet is never re-inserted. Each constant has to be ensured on
        // its own or only the first one ever added would land.
        $script = $this->hardeningScript();

        $this->assertStringContainsString('$constants = [', $script);
        $this->assertStringContainsString('DISABLE_WP_CRON', $script);
        $this->assertStringContainsString('foreach ($constants as $name => $insert)', $script);
        $this->assertStringContainsString('if (str_contains($text, $name)) { continue; }', $script);
    }

    #[Test]
    public function the_updater_has_what_it_needs_to_actually_run(): void
    {
        // WordPress only auto-updates when it can write core directly and when
        // its cron fires. Both are already true here, and this test exists so a
        // change to either is noticed.
        $permissions = app(WordPressContainerHardeningService::class)->inContainerPermissionsScript();

        $this->assertStringContainsString('chown -R www-data:www-data /var/www/html', $permissions);
        $this->assertStringContainsString('/var/www/html/wp-content/upgrade', $permissions);
        $this->assertSame('*/5 * * * *', WordPressContainerHardeningService::WP_CRON_SCHEDULE);
        $this->assertSame('php /var/www/html/wp-cron.php', WordPressContainerHardeningService::WP_CRON_COMMAND);
    }

    private function hardeningScript(): string
    {
        $captured = '';

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$captured): string {
            $captured = $command;

            return '';
        });

        app(WordPressContainerHardeningService::class)
            ->ensureWpConfigHardening($ssh, '/opt/talksasa/containers/site', 'site');

        return $captured;
    }
}
