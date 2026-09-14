<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * One-click repairs for the WordPress findings the analyzer raises. File moves
 * happen on the host bind mount and are reversible from the file manager;
 * wp-config edits run inside the container as www-data.
 */
class ContainerDoctorWordPressTreatments
{
    private const DOCROOT = ContainerDoctorWordPressAnalyzer::DOCROOT;

    private const DEBUG_MARKER = 'TALKASA_DEBUG_LOG';

    public const DEBUG_LOG_PATH = '/tmp/talksasa-wp-debug.log';

    /*
    |--------------------------------------------------------------------------
    | Treatments (each returns {success, message})
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{success: bool, message: string}
     */
    public function disablePlugin(Service $service, string $slug): array
    {
        if (! ContainerDoctorWordPressAnalyzer::isSafeSlug($slug)) {
            return ['success' => false, 'message' => 'That plugin name is not valid.'];
        }

        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment, string $hostAppPath) use ($slug) {
            $result = $ssh->execWithStatus($this->disablePluginCommand($hostAppPath, $slug), 60);
            if ($result['status'] !== 0) {
                return ['success' => false, 'message' => 'Could not move the plugin aside: '.($result['output'] ?: 'unknown error')];
            }
            if (str_contains($result['output'], 'missing')) {
                return ['success' => false, 'message' => 'Plugin folder wp-content/plugins/'.$slug.' is not there; nothing to disable.'];
            }

            return [
                'success' => true,
                'message' => 'Moved '.$slug.' to '.ContainerDoctorWordPressAnalyzer::DISABLED_PLUGINS_DIR.'/. WordPress deactivates it on the next load. Move it back from the file manager after updating it.',
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deactivateMissingPlugins(Service $service): array
    {
        return $this->withWpCli($service, function (SSHService $ssh, ContainerDeployment $deployment, string $containerPath) {
            $script = 'foreach ((array) get_option("active_plugins", []) as $p) { if (! file_exists(WP_PLUGIN_DIR."/".$p)) { echo "MISSING ".$p."\n"; } }';
            $listed = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp eval '.escapeshellarg($script).' --path='.self::DOCROOT.' --skip-plugins --skip-themes');
            $missing = [];
            foreach (explode("\n", $listed['output']) as $line) {
                if (str_starts_with(trim($line), 'MISSING ')) {
                    $missing[] = trim(substr(trim($line), 8));
                }
            }
            if ($missing === []) {
                return ['success' => true, 'message' => 'Every active plugin has its files; nothing to deactivate.'];
            }
            $args = implode(' ', array_map(fn ($p) => escapeshellarg(dirname($p) === '.' ? preg_replace('/\.php$/', '', $p) : dirname($p)), $missing));
            $result = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp plugin deactivate '.$args.' --path='.self::DOCROOT.' --skip-plugins --skip-themes 2>&1 || true');

            return [
                'success' => true,
                'message' => 'Deactivated '.count($missing).' plugin(s) whose files are missing: '.implode(', ', $missing).'. '.mb_substr(trim($result['output']), 0, 160),
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function switchThemeDefault(Service $service): array
    {
        return $this->withWpCli($service, function (SSHService $ssh, ContainerDeployment $deployment, string $containerPath) {
            $list = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp theme list --field=name --path='.self::DOCROOT.' --skip-plugins --skip-themes 2>/dev/null || true');
            $candidates = array_values(array_filter(array_map('trim', explode("\n", $list['output'])), fn ($t) => preg_match('/^twenty[a-z]+$/', $t) === 1));
            rsort($candidates);
            $theme = $candidates[0] ?? null;
            if ($theme === null) {
                $install = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp theme install twentytwentyfour --activate --path='.self::DOCROOT.' --skip-plugins --skip-themes 2>&1 || true');
                if (! str_contains($install['output'], 'Success')) {
                    return ['success' => false, 'message' => 'No bundled default theme is installed and downloading one failed: '.mb_substr(trim($install['output']), 0, 200)];
                }
                $theme = 'twentytwentyfour';
            } else {
                $activate = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp theme activate '.escapeshellarg($theme).' --path='.self::DOCROOT.' --skip-plugins --skip-themes 2>&1 || true');
                if (! str_contains($activate['output'], 'Success')) {
                    return ['success' => false, 'message' => 'Could not activate '.$theme.': '.mb_substr(trim($activate['output']), 0, 200)];
                }
            }

            return ['success' => true, 'message' => 'Activated the default theme '.$theme.'. The site renders again; repair or reinstall the original theme, then switch back under Appearance.'];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function stripPhpPrepend(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment, string $hostAppPath) {
            $result = $ssh->execWithStatus($this->stripPrependCommand($hostAppPath), 60);
            if ($result['status'] !== 0) {
                return ['success' => false, 'message' => 'Could not edit the PHP override files: '.($result['output'] ?: 'unknown error')];
            }

            return ['success' => true, 'message' => 'Removed auto_prepend_file from .user.ini, php.ini and .htaccess. Reload the site; Apache re-reads .htaccess on the next request.'];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function quarantineDropins(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment, string $hostAppPath) {
            $stamp = now()->format('Ymd-His');
            $result = $ssh->execWithStatus($this->quarantineDropinsCommand($hostAppPath, $stamp), 60);
            if ($result['status'] !== 0) {
                return ['success' => false, 'message' => 'Could not move the drop-ins: '.($result['output'] ?: 'unknown error')];
            }
            $moved = array_values(array_filter(array_map('trim', explode("\n", $result['output']))));
            $this->editWpConfig($ssh, $deployment, 'unset_wp_cache');

            return [
                'success' => true,
                'message' => $moved === []
                    ? 'No cache drop-ins or maintenance lock were present.'
                    : 'Moved '.implode(', ', $moved).' to '.ContainerDoctorWordPressAnalyzer::DISABLED_DROPINS_DIR.'/'.$stamp.'/ and set WP_CACHE to false. Deactivate the old caching plugin or reconfigure it for this host.',
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function fixAbspath(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) {
            $out = $this->editWpConfig($ssh, $deployment, 'abspath');

            return str_contains($out, 'CHANGED')
                ? ['success' => true, 'message' => 'ABSPATH now resolves to the WordPress directory inside the container.']
                : ['success' => true, 'message' => 'ABSPATH was already correct; nothing changed.'];
        });
    }

    /**
     * Strip WP_HOME / WP_SITEURL constants so the database options rule again.
     * Returns the removed values for the caller's message.
     *
     * @return list<string>
     */
    public function removeUrlConstants(SSHService $ssh, ContainerDeployment $deployment): array
    {
        $out = $this->editWpConfig($ssh, $deployment, 'remove_url_constants');
        preg_match_all('/REMOVED (\S+)=(\S+)/', $out, $m, PREG_SET_ORDER);

        return array_map(fn ($row) => $row[1].'='.$row[2], $m);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function enableDebugLog(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) {
            $out = $this->editWpConfig($ssh, $deployment, 'enable_debug_log');

            return str_contains($out, 'CHANGED') || str_contains($out, 'PRESENT')
                ? ['success' => true, 'message' => 'Debug logging is on: PHP errors go to '.self::DEBUG_LOG_PATH.' inside the container, never to visitors. Reload the site once, then run Doctor again to read the failing file.']
                : ['success' => false, 'message' => 'Could not edit wp-config.php: '.mb_substr(trim($out), 0, 200)];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function purgePageCache(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) {
            $host = (string) ($deployment->probeHostHeader() ?? '');
            if ($host === '') {
                return ['success' => false, 'message' => 'No hostname is bound to this site, so there is no cache to purge.'];
            }
            $result = $ssh->execWithStatus($this->purgePageCacheCommand($host), 60);

            return $result['status'] === 0
                ? ['success' => true, 'message' => 'Purged cached pages for '.$host.' ('.trim($result['output']).' entries). Visitors get the live response on their next request.']
                : ['success' => false, 'message' => 'Purge failed: '.($result['output'] ?: 'unknown error')];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Command builders (pure, tested with bash -n)
    |--------------------------------------------------------------------------
    */

    public function disablePluginCommand(string $hostAppPath, string $slug): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $target = escapeshellarg(ContainerDoctorWordPressAnalyzer::DISABLED_PLUGINS_DIR);
        $slugArg = escapeshellarg($slug);

        return 'root='.$root.'; slug='.$slugArg.'; target="$root"/'.$target.'; '
            .'if [ -d "$root/wp-content/plugins/$slug" ]; then src="$root/wp-content/plugins/$slug"; '
            .'elif [ -f "$root/wp-content/plugins/$slug.php" ]; then src="$root/wp-content/plugins/$slug.php"; '
            .'else echo missing; exit 0; fi; '
            .'mkdir -p "$target" && chown 33:33 "$target" 2>/dev/null; '
            .'dest="$target/$(basename "$src")"; if [ -e "$dest" ]; then dest="$dest.$(date +%s)"; fi; '
            .'mv "$src" "$dest" && echo "moved $(basename "$src")"';
    }

    public function quarantineDropinsCommand(string $hostAppPath, string $stamp): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $target = escapeshellarg(ContainerDoctorWordPressAnalyzer::DISABLED_DROPINS_DIR.'/'.preg_replace('/[^A-Za-z0-9_-]/', '', $stamp));

        return 'root='.$root.'; target="$root"/'.$target.'; mkdir -p "$target" && chown 33:33 "$target" 2>/dev/null; '
            .'for f in wp-content/advanced-cache.php wp-content/object-cache.php .maintenance; do '
            .'if [ -e "$root/$f" ]; then mkdir -p "$target/$(dirname "$f")" && mv "$root/$f" "$target/$f" && echo "$f"; fi; '
            .'done; true';
    }

    public function stripPrependCommand(string $hostAppPath): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));

        return 'root='.$root.'; '
            .'for f in "$root/.user.ini" "$root/php.ini" "$root/wp-content/.user.ini"; do '
            .'if [ -f "$f" ]; then sed -i -e "/auto_prepend_file/Id" -e "/wordfence-waf\\.php/Id" "$f"; fi; done; '
            .'if [ -f "$root/.htaccess" ]; then sed -i -e "/php_value[[:space:]]\\+auto_prepend_file/Id" -e "/php_admin_value[[:space:]]\\+auto_prepend_file/Id" -e "/wordfence-waf\\.php/Id" "$root/.htaccess"; fi; '
            .'echo stripped';
    }

    public function purgePageCacheCommand(string $host): string
    {
        return 'd=/var/cache/nginx/talksasa; [ -d "$d" ] || { echo 0; exit 0; }; '
            .'n=0; while IFS= read -r -d "" f; do rm -f "$f" && n=$((n+1)); done < <(find "$d" -type f -print0 2>/dev/null | xargs -0 -r grep -laF '.escapeshellarg($host).' | tr "\\n" "\\0"); '
            .'echo "$n"';
    }

    /**
     * PHP that edits /var/www/html/wp-config.php in one of a few modes and prints
     * CHANGED / PRESENT / UNCHANGED plus REMOVED lines.
     */
    public function wpConfigEditScript(string $mode): string
    {
        $debugBlock = '/* '.self::DEBUG_MARKER." */\n"
            ."if (! defined('WP_DEBUG')) { define('WP_DEBUG', true); }\n"
            ."if (! defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', '".self::DEBUG_LOG_PATH."'); }\n"
            ."if (! defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }\n"
            ."@ini_set('display_errors', '0');\n";

        return '$cfg = '.var_export(self::DOCROOT.'/wp-config.php', true).';'
            .' $mode = '.var_export($mode, true).';'
            .' if (! is_file($cfg)) { echo "MISSING\n"; exit(0); }'
            .' $text = file_get_contents($cfg); $orig = $text;'
            .' if ($mode === "abspath") {'
            .'   $text = preg_replace("/define\\s*\\(\\s*[\'\\"]ABSPATH[\'\\"]\\s*,\\s*[^;]*?\\)\\s*;/", "define(\'ABSPATH\', __DIR__ . \'/\');", $text, 1);'
            .' } elseif ($mode === "remove_url_constants") {'
            .'   foreach (["WP_HOME", "WP_SITEURL"] as $name) {'
            .'     if (preg_match("/^[ \\t]*define\\s*\\(\\s*[\'\\"]".$name."[\'\\"]\\s*,\\s*([^)]*)\\)\\s*;[ \\t]*\\r?\\n?/m", $text, $m)) {'
            .'       echo "REMOVED ".$name."=".trim($m[1], " \'\\"")."\n";'
            .'       $text = str_replace($m[0], "", $text);'
            .'     }'
            .'   }'
            .' } elseif ($mode === "unset_wp_cache") {'
            .'   $text = preg_replace("/define\\s*\\(\\s*[\'\\"]WP_CACHE[\'\\"]\\s*,\\s*true\\s*\\)/i", "define(\'WP_CACHE\', false)", $text);'
            .' } elseif ($mode === "enable_debug_log") {'
            .'   if (str_contains($text, "'.self::DEBUG_MARKER.'")) { echo "PRESENT\n"; exit(0); }'
            // The block below defines these first, so a later define() of the
            // same name would only add a "already defined" warning to the log.
            .'   $text = preg_replace("/^[ \\t]*define\\s*\\(\\s*[\'\\"](WP_DEBUG|WP_DEBUG_LOG|WP_DEBUG_DISPLAY)[\'\\"]\\s*,[^;]*;[ \\t]*\\r?\\n?/m", "", $text);'
            .'   $block = '.var_export($debugBlock, true).';'
            .'   $count = 0; $text = preg_replace("/<\\?php\\b/", "<?php\n".$block, $text, 1, $count);'
            .'   if ($count === 0) { $text = "<?php\n".$block.$text; }'
            .' }'
            .' if ($text !== $orig) { file_put_contents($cfg, $text); echo "CHANGED\n"; } else { echo "UNCHANGED\n"; }';
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function editWpConfig(SSHService $ssh, ContainerDeployment $deployment, string $mode): string
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        return (string) $ssh->exec(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
            .' php -r '.escapeshellarg($this->wpConfigEditScript($mode)).' 2>&1 || true',
            60,
        );
    }

    /**
     * @return array{output: string, status: int}
     */
    private function runWp(SSHService $ssh, string $containerPath, string $appService, string $command): array
    {
        return $ssh->execWithStatus(
            'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' sh -lc '.escapeshellarg($command),
            300,
        );
    }

    /**
     * @param  callable(SSHService, ContainerDeployment, string): array{success: bool, message: string}  $work  receives the host app path
     * @return array{success: bool, message: string}
     */
    private function onHost(Service $service, callable $work): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }
        $ssh = SSHService::forNode($deployment->node);
        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';

        try {
            return $work($ssh, $deployment, $hostAppPath);
        } catch (\Throwable $e) {
            Log::warning('WordPress doctor treatment failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Repair failed: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @param  callable(SSHService, ContainerDeployment, string): array{success: bool, message: string}  $work  receives the compose project path
     * @return array{success: bool, message: string}
     */
    private function withWpCli(Service $service, callable $work): array
    {
        $deployment = $service->containerDeployment;
        if (! $deployment?->node) {
            return ['success' => false, 'message' => 'Application is not deployed.'];
        }
        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            app(WordPressAppInstallationService::class)->ensureWpCli($ssh, $containerPath, $deployment->container_name);

            return $work($ssh, $deployment, $containerPath);
        } catch (\Throwable $e) {
            Log::warning('WordPress doctor treatment failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Repair failed: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }
}
