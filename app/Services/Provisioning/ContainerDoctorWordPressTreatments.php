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
     * wp-cli refuses to deactivate a plugin whose files are gone, so the
     * option itself is edited and read back.
     *
     * @return array{success: bool, message: string}
     */
    public function deactivateMissingPlugins(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) {
            $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            // Plain PHP with wp-load: no wp-cli dependency. Plugins whose files are
            // missing are skipped by WordPress itself, so loading is safe here.
            $script = "@ini_set('display_errors', '0'); error_reporting(0); "
                ."ob_start(); require '".self::DOCROOT."/wp-load.php'; ob_end_clean();\n".$this->deactivateMissingPluginsScript();
            $result = $ssh->execWithStatus(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
                .' php -d display_errors=0 -r '.escapeshellarg($script).' 2>&1',
                120
            );
            if (preg_match('/TALKSASA_DEACTIVATED=(\{.*\})/', $result['output'], $m) !== 1 || ! is_array($parsed = json_decode($m[1], true))) {
                return ['success' => false, 'message' => 'WordPress did not answer: '.mb_substr(trim($result['output']), 0, 200)];
            }
            $removed = (array) ($parsed['removed'] ?? []);
            $remaining = (array) ($parsed['still_missing'] ?? []);
            if ($removed === [] && $remaining === []) {
                return ['success' => true, 'message' => 'Every active plugin has its files; nothing to deactivate.'];
            }
            if ($remaining !== []) {
                return ['success' => false, 'message' => 'Could not update the active plugin list; still listed without files: '.implode(', ', $remaining)];
            }

            return [
                'success' => true,
                'message' => 'Deactivated '.count($removed).' plugin(s) whose files are missing: '.implode(', ', $removed).'. WordPress no longer tries to load them.',
            ];
        });
    }

    public function deactivateMissingPluginsScript(): string
    {
        return <<<'PHP'
$removed = [];
foreach (['active_plugins' => false, 'active_sitewide_plugins' => true] as $option => $network) {
    $current = $network ? (is_multisite() ? (array) get_site_option($option, []) : []) : (array) get_option($option, []);
    if ($current === []) { continue; }
    $kept = [];
    foreach ($current as $key => $value) {
        $file = $network ? (string) $key : (string) $value;
        if (file_exists(WP_PLUGIN_DIR.'/'.$file)) { if ($network) { $kept[$key] = $value; } else { $kept[] = $value; } } else { $removed[] = $file; }
    }
    if (count($kept) !== count($current)) { $network ? update_site_option($option, $kept) : update_option($option, $kept); }
}
wp_cache_delete('active_plugins', 'options'); wp_cache_delete('alloptions', 'options');
$still = [];
foreach ((array) get_option('active_plugins', []) as $p) { if (! file_exists(WP_PLUGIN_DIR.'/'.$p)) { $still[] = $p; } }
echo "TALKSASA_DEACTIVATED=".json_encode(['removed' => array_values(array_unique($removed)), 'still_missing' => $still])."\n";
PHP;
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
            // The plugin that wrote the drop-in writes it again on the next request
            // while it is active, so it is disabled first.
            $owners = [];
            foreach (['wp-content/advanced-cache.php', 'wp-content/object-cache.php'] as $dropin) {
                $head = (string) $ssh->exec('head -c 8192 '.escapeshellarg(rtrim($hostAppPath, '/').'/'.$dropin).' 2>/dev/null; true', 15);
                $owner = $this->dropinOwner($head);
                if ($owner !== null && ! isset($owners[$owner['slug']])) {
                    $owners[$owner['slug']] = $owner;
                }
            }
            $disabled = [];
            foreach ($owners as $owner) {
                $moved = $ssh->execWithStatus($this->disablePluginCommand($hostAppPath, $owner['slug']), 60);
                if (str_contains($moved['output'], 'moved')) {
                    $disabled[] = $owner['name'];
                }
            }

            $stamp = now()->format('Ymd-His');
            $result = $ssh->execWithStatus($this->quarantineDropinsCommand($hostAppPath, $stamp), 60);
            if ($result['status'] !== 0) {
                return ['success' => false, 'message' => 'Could not move the drop-ins: '.($result['output'] ?: 'unknown error')];
            }
            $moved = array_values(array_filter(array_map('trim', explode("\n", $result['output']))));
            $this->editWpConfig($ssh, $deployment, 'unset_wp_cache');

            $left = trim((string) $ssh->exec('cd '.escapeshellarg($hostAppPath).' && ls wp-content/advanced-cache.php wp-content/object-cache.php .maintenance 2>/dev/null; true', 15));
            if ($left !== '') {
                return ['success' => false, 'message' => 'Still present after the move: '.str_replace("\n", ', ', $left).'. A plugin keeps writing it; disable the caching plugin from wp-admin and run Doctor again.'];
            }

            return [
                'success' => true,
                'message' => ($moved === [] ? 'No cache drop-ins or maintenance lock were present.' : 'Moved '.implode(', ', $moved).' to '.ContainerDoctorWordPressAnalyzer::DISABLED_DROPINS_DIR.'/'.$stamp.'/ and set WP_CACHE to false.')
                    .($disabled !== [] ? ' Disabled '.implode(' and ', $disabled).', which kept writing the drop-in; this container runs Apache with the platform page cache in front, so that plugin has nothing to accelerate here. Its folder is under '.ContainerDoctorWordPressAnalyzer::DISABLED_PLUGINS_DIR.'/.' : ''),
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

    /**
     * @return array{success: bool, message: string}
     */
    public function rotateSecurityKeys(Service $service): array
    {
        return $this->onHost($service, fn (SSHService $ssh, ContainerDeployment $deployment) => app(WordPressSecurityBaseline::class)->rotateSalts($ssh, $deployment));
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function closeRegistration(Service $service): array
    {
        return $this->withWpCli($service, function (SSHService $ssh, ContainerDeployment $deployment, string $containerPath) {
            $script = 'update_option("default_role", "subscriber"); echo "ROLE=".get_option("default_role")."\n";';
            $result = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp eval '.escapeshellarg($script).' --path='.self::DOCROOT.' --skip-plugins --skip-themes 2>&1');
            if (! str_contains($result['output'], 'ROLE=subscriber')) {
                return ['success' => false, 'message' => 'The default role did not change: '.mb_substr(trim($result['output']), 0, 200)];
            }

            return ['success' => true, 'message' => 'New registrations are now Subscribers. Review existing administrators under wp-admin → Users.'];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function hardenRuntime(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) {
            $baseline = app(WordPressSecurityBaseline::class);
            $lines = $baseline->apply($ssh, $deployment->container_name);
            $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            $state = $baseline->hostState($ssh, $containerPath, $deployment->container_name);
            $ok = $state['conf_present'] && $state['conf_version_ok'];

            return [
                'success' => $ok,
                'message' => ($ok ? 'Runtime hardened. ' : 'Hardening did not fully apply. ').implode(' ', $lines),
            ];
        });
    }

    /**
     * Update every plugin and theme, then boot WordPress once. A plugin that
     * now fatals is moved aside and named.
     *
     * @return array{success: bool, message: string}
     */
    public function updateExtensions(Service $service): array
    {
        return $this->withWpCli($service, function (SSHService $ssh, ContainerDeployment $deployment, string $containerPath) {
            $this->recordVersions($ssh, $deployment, $containerPath, 'extensions');
            $path = ' --path='.self::DOCROOT;
            $plugins = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp plugin update --all --format=summary'.$path.' 2>&1 || true');
            $themes = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp theme update --all --format=summary'.$path.' 2>&1 || true');
            $updated = $this->countUpdated($plugins['output']) + $this->countUpdated($themes['output']);
            $failed = $this->failedUpdates($plugins['output'].$themes['output']);

            $boot = $this->bootCheck($ssh, $deployment, $containerPath);
            $message = 'Updated '.$updated.' plugin(s)/theme(s).';
            if ($failed !== []) {
                $message .= ' Could not update: '.implode(', ', array_slice($failed, 0, 6)).'.';
            }

            return $this->finishWithBoot($ssh, $boot, $message, $updated > 0 || $failed === []);
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateCore(Service $service): array
    {
        return $this->withWpCli($service, function (SSHService $ssh, ContainerDeployment $deployment, string $containerPath) {
            $this->recordVersions($ssh, $deployment, $containerPath, 'core');
            $path = ' --path='.self::DOCROOT;
            $before = trim($this->runWp($ssh, $containerPath, $deployment->container_name, 'wp core version'.$path.' 2>/dev/null')['output']);
            $update = $this->runWp($ssh, $containerPath, $deployment->container_name, 'wp core update'.$path.' 2>&1 && wp core update-db'.$path.' 2>&1');
            $after = trim($this->runWp($ssh, $containerPath, $deployment->container_name, 'wp core version'.$path.' 2>/dev/null')['output']);
            if ($update['status'] !== 0 && $after === $before) {
                return ['success' => false, 'message' => 'Core update did not complete: '.mb_substr(trim($update['output']), 0, 300)];
            }
            $boot = $this->bootCheck($ssh, $deployment, $containerPath);

            return $this->finishWithBoot($ssh, $boot, $after === $before ? 'WordPress is already at '.$after.'.' : 'WordPress updated from '.$before.' to '.$after.' and the database schema was upgraded.', true);
        });
    }

    /**
     * Save the original rows into an incident folder, then remove only the
     * flagged script tags, then probe again.
     *
     * @return array{success: bool, message: string}
     */
    public function stripScriptInjection(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment) use ($service) {
            $baseline = app(WordPressSecurityBaseline::class);
            $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            $hosts = array_values(array_filter(array_map(fn ($d) => (string) $d->domain, $deployment->domains()->get()->all())));
            $sec = $baseline->parseSecurityProbe((string) $ssh->exec($baseline->securityProbeCommand($containerPath, $deployment->container_name, $hosts), 120, false));
            $inj = is_array($sec) ? (array) ($sec['injection'] ?? []) : [];
            $options = array_values(array_filter(array_map(fn ($o) => (string) ($o['name'] ?? ''), (array) ($inj['options'] ?? []))));
            $posts = array_values(array_filter(array_map(fn ($p) => (int) ($p['id'] ?? 0), (array) ($inj['posts'] ?? []))));
            if ($options === [] && $posts === []) {
                return ['success' => true, 'message' => 'No injected scripts are left in the database.'];
            }
            $payload = json_encode(['options' => $options, 'posts' => $posts, 'domains' => (array) ($inj['domains'] ?? [])]);
            $out = (string) $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -u www-data -T '.escapeshellarg($deployment->container_name)
                .' php -d display_errors=0 -r '.escapeshellarg($baseline->stripInjectionScript()).' '.escapeshellarg((string) $payload).' 2>&1 || true',
                180,
                false
            );
            $parsed = $baseline->parseStripOutput($out);
            if ($parsed['cleaned'] === null) {
                return ['success' => false, 'message' => 'The clean-up did not run: '.mb_substr(trim($out), 0, 200)];
            }
            $incidents = app(ContainerIncidentService::class);
            $incidentId = null;
            try {
                $id = $incidents->newIncidentId();
                $dir = $incidents->incidentsDir($deployment).'/'.$id;
                $ssh->exec('mkdir -p '.escapeshellarg($dir).' && chmod 700 '.escapeshellarg($dir), 15);
                $ssh->upload((string) json_encode(['id' => $id, 'kind' => ContainerIncidentService::KIND_INJECTION, 'opened_at' => now()->toIso8601String(), 'rows' => $parsed['backup'], 'cleaned' => $parsed['cleaned']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $dir.'/injection-rows.json');
                $incidentId = $id;
            } catch (\Throwable $e) {
                Log::warning('Injection backup not written', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            }
            $after = $baseline->parseSecurityProbe((string) $ssh->exec($baseline->securityProbeCommand($containerPath, $deployment->container_name, $hosts), 120, false));
            $left = is_array($after) ? count((array) ($after['injection']['options'] ?? [])) + count((array) ($after['injection']['posts'] ?? [])) : -1;
            $c = $parsed['cleaned'];

            return [
                'success' => $left === 0,
                'message' => 'Removed '.$c['tags'].' injected script tag(s) from '.$c['posts'].' post(s) and '.$c['options'].' option(s)'
                    .($incidentId ? '; the original rows are saved under incident '.$incidentId : '')
                    .($left > 0 ? '. '.$left.' item(s) still match; open them in wp-admin' : '. Purge the page cache so visitors get the clean pages.'),
            ];
        });
    }

    /**
     * @return array{fatal: ?array{message: string, file: string, line: int}, loaded: bool, disabled: ?string}
     */
    private function bootCheck(SSHService $ssh, ContainerDeployment $deployment, string $containerPath): array
    {
        $analyzer = app(ContainerDoctorWordPressAnalyzer::class);
        $runtime = $analyzer->parseRuntimeProbe((string) $ssh->exec($analyzer->runtimeProbeCommand($containerPath, $deployment->container_name), 120, false));
        $fatal = is_array($runtime['fatal'] ?? null) ? $runtime['fatal'] : null;
        $disabled = null;
        if ($fatal !== null) {
            $owner = $analyzer->ownerOfPath((string) ($fatal['file'] ?? ''));
            if ($owner && $owner['kind'] === 'plugin' && ContainerDoctorWordPressAnalyzer::isSafeSlug($owner['slug'])) {
                $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
                $moved = $ssh->execWithStatus($this->disablePluginCommand($hostAppPath, $owner['slug']), 60);
                if (str_contains($moved['output'], 'moved')) {
                    $disabled = $owner['slug'];
                }
            }
        }

        return ['fatal' => $fatal, 'loaded' => (bool) ($runtime['loaded'] ?? false), 'disabled' => $disabled];
    }

    /**
     * @param  array{fatal: ?array{message: string, file: string, line: int}, loaded: bool, disabled: ?string}  $boot
     * @return array{success: bool, message: string}
     */
    private function finishWithBoot(SSHService $ssh, array $boot, string $message, bool $success): array
    {
        if ($boot['fatal'] === null) {
            return ['success' => $success, 'message' => $message.' WordPress boots cleanly afterwards.'];
        }
        $where = basename((string) ($boot['fatal']['file'] ?? ''));

        return [
            'success' => false,
            'message' => $message.' After the update WordPress fatals in '.$where.': '.mb_substr((string) $boot['fatal']['message'], 0, 160)
                .($boot['disabled'] ? '. Doctor moved the '.$boot['disabled'].' plugin to '.ContainerDoctorWordPressAnalyzer::DISABLED_PLUGINS_DIR.'/ so the site renders; update or replace it, then move it back' : ''),
        ];
    }

    private function recordVersions(SSHService $ssh, ContainerDeployment $deployment, string $containerPath, string $what): void
    {
        try {
            $path = ' --path='.self::DOCROOT.' --skip-plugins --skip-themes';
            $out = $this->runWp($ssh, $containerPath, $deployment->container_name,
                'echo "{\"core\":\"$(wp core version'.$path.' 2>/dev/null)\",\"plugins\":$(wp plugin list --format=json --fields=name,version,status'.$path.' 2>/dev/null || echo []),\"themes\":$(wp theme list --format=json --fields=name,version,status'.$path.' 2>/dev/null || echo [])}"'
            );
            $dir = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/updates/'.now()->format('Ymd-His').'-'.$what;
            $ssh->exec('mkdir -p '.escapeshellarg($dir).' && chmod 700 '.escapeshellarg($dir), 15);
            $ssh->upload(trim($out['output'])."\n", $dir.'/versions.json');
        } catch (\Throwable $e) {
            Log::info('Pre-update version list not recorded', ['container' => $deployment->container_name, 'error' => $e->getMessage()]);
        }
    }

    private function countUpdated(string $output): int
    {
        if (preg_match('/Success: Updated (\d+) of (\d+)/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return preg_match_all('/^\S+\s+\S+\s+\S+\s+Updated\s*$/m', $output);
    }

    /**
     * @return list<string>
     */
    private function failedUpdates(string $output): array
    {
        preg_match_all('/^(\S+)\s+\S+\s+\S+\s+Error\s*$/m', $output, $m);
        preg_match_all('/Warning: (?:The )?[\'"]?([\w.-]+)[\'"]? (?:plugin|theme) (?:could not be updated|update failed)/i', $output, $w);

        return array_values(array_unique(array_merge($m[1], $w[1])));
    }

    /**
     * Which caching plugin wrote a drop-in, from its header.
     *
     * @return array{slug: string, name: string}|null
     */
    public function dropinOwner(string $contents): ?array
    {
        $owners = [
            'litespeed-cache' => ['LiteSpeed', 'LSCWP'],
            'w3-total-cache' => ['W3 Total Cache', 'W3TC'],
            'wp-rocket' => ['WP Rocket', 'WP_ROCKET'],
            'wp-optimize' => ['WP-Optimize', 'WPO_CACHE', 'wpo-cache'],
            'wp-super-cache' => ['WP Super Cache', 'WPCACHEHOME', 'wp-cache-phase'],
            'hummingbird-performance' => ['Hummingbird'],
            'cache-enabler' => ['Cache Enabler'],
            'breeze' => ['Breeze'],
            'swift-performance' => ['Swift Performance'],
            'comet-cache' => ['Comet Cache'],
            'wp-fastest-cache' => ['WP Fastest Cache', 'WpFastestCache'],
            'sg-cachepress' => ['SiteGround', 'SG Optimizer'],
            'nitropack' => ['NitroPack'],
            'redis-cache' => ['Redis Object Cache', 'redis-cache'],
            'object-cache-pro' => ['Object Cache Pro'],
            'memcached' => ['Memcached Object Cache'],
            'docket-cache' => ['Docket Cache'],
        ];
        foreach ($owners as $slug => $needles) {
            foreach ($needles as $needle) {
                if (stripos($contents, $needle) !== false) {
                    return ['slug' => $slug, 'name' => $needles[0]];
                }
            }
        }

        return null;
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
