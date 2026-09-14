<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * Finds files that do not belong in a customer's application directory.
 *
 * Runs on the container host over the bind mount, read-only, with caps on
 * file size and hit count. Each rule family emits its own reason so a hit
 * explains itself; contents are never included in evidence. Quarantine moves
 * files outside the mount with their paths preserved and a manifest, so a
 * false positive can be put back.
 */
class ContainerIntegrityScanner
{
    public const REASON_PHP_IN_UPLOADS = 'php_in_uploads';

    public const REASON_HTACCESS_PHP = 'htaccess_php_handler';

    public const REASON_MU_PLUGIN = 'unexpected_mu_plugin';

    public const REASON_KNOWN_FAMILY = 'known_webshell_family';

    public const REASON_ROOT_PHP = 'unexpected_root_php';

    public const REASON_CORE_LOOKALIKE = 'core_lookalike';

    public const REASON_RANDOM_NAME = 'random_name';

    public const REASON_PHP_IN_IMAGE = 'php_in_image';

    public const REASON_SIGNATURE = 'signature';

    public const REASON_CORE_TOUCHED = 'core_modified_after_install';

    public const REASON_CORE_CHECKSUM = 'core_checksum_mismatch';

    public const REASON_CORE_EXTRA = 'core_unexpected_file';

    /** Reasons confident enough to quarantine without an operator's click. */
    public const AUTO_QUARANTINE_REASONS = [
        self::REASON_KNOWN_FAMILY,
        self::REASON_PHP_IN_UPLOADS,
        self::REASON_HTACCESS_PHP,
        self::REASON_PHP_IN_IMAGE,
        self::REASON_MU_PLUGIN,
    ];

    /** Reasons the quarantine treatment moves aside. */
    public const QUARANTINE_REASONS = [
        self::REASON_KNOWN_FAMILY,
        self::REASON_PHP_IN_UPLOADS,
        self::REASON_HTACCESS_PHP,
        self::REASON_PHP_IN_IMAGE,
        self::REASON_MU_PLUGIN,
        self::REASON_ROOT_PHP,
        self::REASON_CORE_LOOKALIKE,
        self::REASON_RANDOM_NAME,
        self::REASON_SIGNATURE,
        self::REASON_CORE_EXTRA,
    ];

    /** Files WordPress ships in its root. Anything else ending in .php there is foreign. */
    public const CORE_ROOT_FILES = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config.php',
        'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
        'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
    ];

    private const SIGNATURES = [
        'eval[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin)',
        'preg_replace[[:space:]]*\(.*/e["'."'".']',
        '(system|passthru|shell_exec|popen|proc_open|exec)[[:space:]]*\([[:space:]]*\$_(GET|POST|REQUEST|COOKIE)',
        'assert[[:space:]]*\([[:space:]]*\$_(GET|POST|REQUEST|COOKIE)',
        '\$_(GET|POST|REQUEST|COOKIE)\[[^]]+\][[:space:]]*\([[:space:]]*\$_(GET|POST|REQUEST|COOKIE)',
        'FilesMan|alfacgiapi|c99sh|r57shell|b374k|IndoXploit|WSO[[:space:]]?[0-9]|Web[[:space:]]?Shell[[:space:]]?by',
        'eval[[:space:]]*\([[:space:]]*["'."'".'][A-Za-z0-9+/=]{200,}',
        'move_uploaded_file[[:space:]]*\([[:space:]]*\$_FILES\[[^]]+\]\[.tmp_name.\][[:space:]]*,[[:space:]]*\$_(GET|POST|REQUEST)',
    ];

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */

    /**
     * One bash program that prints "reason<TAB>size<TAB>mtime<TAB>path" lines.
     *
     * @param  list<string>  $allowedMuPlugins
     */
    public function scanCommand(string $hostAppPath, bool $wordpress, array $allowedMuPlugins = []): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $maxKb = max(64, (int) config('containers.integrity.max_file_kb', 2048));
        $maxHits = max(20, (int) config('containers.integrity.max_hits_per_rule', 200));
        $allow = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $allowedMuPlugins !== [] ? $allowedMuPlugins : ['talksasa-admin-sso.php']));
        $signatures = implode(' ', array_map(fn ($s) => '-e '.escapeshellarg($s), self::SIGNATURES));
        $coreRoot = implode('|', array_map(fn ($n) => preg_quote($n, '/'), self::CORE_ROOT_FILES));

        $script = <<<BASH
root=$root
cd "\$root" 2>/dev/null || exit 0
emit() { f="\$2"; s=\$(stat -c %s "\$f" 2>/dev/null || echo 0); m=\$(stat -c %Y "\$f" 2>/dev/null || echo 0); printf '%s\\t%s\\t%s\\t%s\\n' "\$1" "\$s" "\$m" "\${f#./}"; }
# Executable code where only data may live.
find ./wp-content/uploads ./wp-content/languages ./storage/app/public ./public/uploads -type f \\( -iname '*.php' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.php[0-9]' -o -iname '*.pht' \\) 2>/dev/null | head -n $maxHits | while IFS= read -r f; do emit php_in_uploads "\$f"; done
find ./wp-content/uploads ./public/uploads -type f -name '.htaccess' -print0 2>/dev/null | xargs -0 -r grep -lEi 'AddHandler|SetHandler|AddType[^\\n]*php|php_value|php_flag' 2>/dev/null | head -n 50 | while IFS= read -r f; do emit htaccess_php_handler "\$f"; done
find . -type f \\( -iname '*.ico' -o -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.gif' -o -iname '*.svg' -o -iname '*.txt' \\) -size -256k -not -path '*/node_modules/*' -print0 2>/dev/null | xargs -0 -r grep -laE '<\\?php' 2>/dev/null | head -n 50 | while IFS= read -r f; do emit php_in_image "\$f"; done
# Families seen on the compromised DirectAdmin node.
find . -type f \\( -name 'wp-homes.php' -o -name 'wp-conf1g.php' -o -name 'wp-l0gin.php' -o -name 'wp-includes.php' -o -name 'wp-admins.php' \\) 2>/dev/null | head -n 50 | while IFS= read -r f; do emit known_webshell_family "\$f"; done
find . -type d \\( -name alfacgiapi -o -name jancox -o -name ALFA_DATA -o -name .well-known-pki \\) 2>/dev/null | head -n 20 | while IFS= read -r f; do emit known_webshell_family "\$f"; done
# Webshell signatures inside code.
find . -type f \\( -iname '*.php' -o -iname '*.phtml' -o -iname '*.inc' -o -iname '*.php[0-9]' \\) -size -{$maxKb}k -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path './wp-content/talksasa-disabled/*' -print0 2>/dev/null | xargs -0 -r grep -lE $signatures 2>/dev/null | head -n $maxHits | while IFS= read -r f; do emit signature "\$f"; done
BASH;

        if ($wordpress) {
            $script .= <<<BASH

# WordPress-only name rules: anything not shipped by core.
find . -maxdepth 1 -type f -iname '*.php' 2>/dev/null | while IFS= read -r f; do b=\$(basename "\$f"); if ! printf '%s' "\$b" | grep -qE '^($coreRoot)\$'; then emit unexpected_root_php "\$f"; fi; done
find ./wp-admin ./wp-includes -maxdepth 2 -type f -iname '*.php' -regextype posix-extended -regex '.*/[a-z0-9]{4,12}\\.php' 2>/dev/null | head -n $maxHits | while IFS= read -r f; do emit random_name_candidate "\$f"; done
find ./wp-content -maxdepth 1 -type f -iname '*.php' 2>/dev/null | while IFS= read -r f; do b=\$(basename "\$f"); case "\$b" in index.php|advanced-cache.php|object-cache.php|db.php|db-error.php|sunrise.php|maintenance.php|blog-deleted.php|blog-inactive.php|blog-suspended.php|fatal-error-handler.php|php-error.php) ;; *) emit random_name_candidate "\$f";; esac; done
find ./wp-content/mu-plugins -maxdepth 1 -type f -iname '*.php' 2>/dev/null | while IFS= read -r f; do b=\$(basename "\$f"); if ! printf '%s' "\$b" | grep -qE '^($allow)\$'; then emit unexpected_mu_plugin "\$f"; fi; done
# Core files changed after the version file was written (an update rewrites both together).
if [ -f ./wp-includes/version.php ]; then ref=\$(( \$(stat -c %Y ./wp-includes/version.php) + 3600 )); find ./wp-admin ./wp-includes -type f -iname '*.php' -newermt "@\$ref" 2>/dev/null | head -n $maxHits | while IFS= read -r f; do emit core_modified_after_install "\$f"; done; fi
BASH;
        }

        return $script."\ntrue\n";
    }

    public function checksumCommand(string $containerPath, string $appService): string
    {
        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' sh -lc '.escapeshellarg('wp core verify-checksums --path='.ContainerDoctorWordPressAnalyzer::DOCROOT.' --skip-plugins --skip-themes 2>&1 || true');
    }

    /**
     * Move hits into a timestamped quarantine folder outside the mount.
     *
     * @param  list<string>  $relPaths
     */
    public function quarantineCommand(string $hostAppPath, string $quarantineDir, array $relPaths): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $target = escapeshellarg(rtrim($quarantineDir, '/'));
        $list = implode(' ', array_map(fn ($p) => escapeshellarg(ltrim($p, '/')), $relPaths));

        return 'root='.$root.'; q='.$target.'; mkdir -p "$q" && chmod 700 "$q"; n=0; '
            .'for rel in '.$list.'; do case "$rel" in ..*|*/..*|/*) continue;; esac; '
            .'src="$root/$rel"; [ -e "$src" ] || continue; mkdir -p "$q/$(dirname "$rel")" && mv "$src" "$q/$rel" && n=$((n+1)) && printf \'%s\\n\' "$rel"; done; '
            .'echo "__MOVED__:$n"';
    }

    public function restoreCoreCommand(string $containerPath, string $appService): string
    {
        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -u www-data -T '.escapeshellarg($appService)
            .' sh -lc '.escapeshellarg('v=$(wp core version --path='.ContainerDoctorWordPressAnalyzer::DOCROOT.' --skip-plugins --skip-themes 2>/dev/null); '
                .'wp core download --force --skip-content --version="$v" --path='.ContainerDoctorWordPressAnalyzer::DOCROOT.' --skip-plugins --skip-themes 2>&1');
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array{path: string, reasons: list<string>, size: int, mtime: int}>
     */
    public function parseScan(string $output): array
    {
        $hits = [];
        foreach (explode("\n", $output) as $line) {
            $parts = explode("\t", rtrim($line, "\r"));
            if (count($parts) !== 4) {
                continue;
            }
            [$reason, $size, $mtime, $path] = $parts;
            $path = trim($path);
            if ($path === '' || $path === '.') {
                continue;
            }
            if ($reason === 'random_name_candidate') {
                if (! $this->looksRandom(basename($path))) {
                    continue;
                }
                $reason = self::REASON_RANDOM_NAME;
            }
            if (! isset($hits[$path])) {
                $hits[$path] = ['path' => $path, 'reasons' => [], 'size' => (int) $size, 'mtime' => (int) $mtime];
            }
            if (! in_array($reason, $hits[$path]['reasons'], true)) {
                $hits[$path]['reasons'][] = $reason;
            }
        }

        return array_values($hits);
    }

    /**
     * @return array{modified: list<string>, extra: list<string>, ran: bool}
     */
    public function parseChecksums(string $output): array
    {
        $modified = [];
        $extra = [];
        $ran = str_contains($output, 'verif') || str_contains($output, 'Warning:') || str_contains($output, 'Success');
        foreach (explode("\n", $output) as $line) {
            if (preg_match("/doesn't verify against checksum: (\S+)/", $line, $m) === 1) {
                $modified[] = $m[1];
            } elseif (preg_match('/should not exist: (\S+)/', $line, $m) === 1) {
                $extra[] = $m[1];
            }
        }

        return ['modified' => $modified, 'extra' => $extra, 'ran' => $ran];
    }

    /**
     * Short lowercase alphanumeric names with few vowels, as droppers generate them.
     */
    public function looksRandom(string $basename): bool
    {
        $name = strtolower((string) preg_replace('/\.php$/i', '', $basename));
        if (in_array($name.'.php', self::CORE_ROOT_FILES, true) || in_array($name, ['index', 'admin', 'load', 'error', 'about', 'config', 'setup', 'debug', 'cache', 'class', 'plugin', 'theme', 'widgets', 'media', 'users', 'edit', 'post', 'link', 'menu', 'ajax', 'cron', 'feed', 'rss', 'atom', 'update', 'upgrade', 'install', 'import', 'export', 'options', 'tools', 'themes', 'plugins', 'comment', 'terms', 'upload', 'moderation', 'privacy', 'revision', 'network', 'customize', 'credits', 'freedoms', 'site', 'user', 'ms', 'l10n', 'kses', 'http', 'query', 'rewrite', 'script', 'shortcodes', 'taxonomy', 'template', 'vars', 'version', 'canonical', 'capabilities', 'category', 'compat', 'cron', 'deprecated', 'embed', 'formatting', 'functions', 'general', 'locale', 'meta', 'nav', 'pluggable', 'post', 'registration', 'rest', 'robots', 'session', 'sitemaps', 'style', 'theme', 'blocks', 'fonts', 'html', 'https', 'interactivity', 'json', 'link', 'load', 'pomo', 'random', 'speculative', 'text', 'wp', 'getid3', 'smtp', 'pop3', 'oauth', 'exception', 'phpmailer', 'autoload', 'bookmark', 'misc', 'noop', 'schema', 'screen', 'term', 'file', 'image', 'dashboard', 'profile', 'settings', 'sites'], true)) {
            return false;
        }
        if (preg_match('/^[a-z0-9]{4,12}$/', $name) !== 1) {
            return false;
        }
        $letters = preg_replace('/[^a-z]/', '', $name) ?? '';
        $digits = strlen($name) - strlen($letters);
        $vowels = preg_match_all('/[aeiouy]/', $letters);
        $ratio = strlen($letters) > 0 ? $vowels / strlen($letters) : 0.0;
        preg_match_all('/[^aeiouy]+/', $letters, $runs);
        $longestRun = max(array_map('strlen', $runs[0] ?: ['']));

        // Droppers mix digits into short names ("iisgg8", "qzxvb7k"); real
        // files rarely carry a digit at all, and when they do the letters read.
        if ($digits >= 2) {
            return true;
        }
        if ($digits === 1) {
            return $ratio < 0.45 || $longestRun >= 3;
        }

        return $ratio < 0.2 || $longestRun >= 5 || (strlen($letters) >= 6 && $vowels <= 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Scan + findings
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>, core: array{modified: list<string>, extra: list<string>, ran: bool}, scanned_at: string}
     */
    public function scan(SSHService $ssh, ContainerDeployment $deployment, bool $wordpress, bool $withChecksums = true): array
    {
        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $allow = (array) config('containers.integrity.mu_plugin_allowlist', ['talksasa-admin-sso.php']);

        $output = $ssh->execWithStatus($this->scanCommand($hostAppPath, $wordpress, $allow), 300);
        $hits = $this->parseScan($output['output']);

        $core = ['modified' => [], 'extra' => [], 'ran' => false];
        if ($wordpress && $withChecksums) {
            try {
                app(WordPressAppInstallationService::class)->ensureWpCli($ssh, $containerPath, $deployment->container_name);
                $checks = $ssh->execWithStatus($this->checksumCommand($containerPath, $deployment->container_name), 180);
                $core = $this->parseChecksums($checks['output']);
            } catch (\Throwable) {
                $core['ran'] = false;
            }
        }

        foreach ($core['extra'] as $path) {
            $hits[] = ['path' => $path, 'reasons' => [self::REASON_CORE_EXTRA], 'size' => 0, 'mtime' => 0];
        }

        return ['hits' => $this->dedupe($hits), 'core' => $core, 'scanned_at' => now()->toIso8601String()];
    }

    /**
     * @param  array{hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>, core: array{modified: list<string>, extra: list<string>, ran: bool}}  $result
     * @return list<array<string, mixed>>
     */
    public function findings(array $result, bool $wordpress = true): array
    {
        $findings = [];
        $suspicious = array_values(array_filter($result['hits'], fn ($h) => array_intersect($h['reasons'], self::QUARANTINE_REASONS) !== []));
        $touched = array_values(array_filter($result['hits'], fn ($h) => in_array(self::REASON_CORE_TOUCHED, $h['reasons'], true)));

        if ($suspicious !== []) {
            $findings[] = [
                'id' => 'integrity_suspicious_files',
                'severity' => 'critical',
                'title' => count($suspicious).' file(s) that do not belong to this application',
                'summary' => 'These files carry webshell code, sit where executable code never belongs, or have names WordPress never ships. '
                    .'Quarantine moves them outside the application directory with their paths preserved, so a false positive can be restored from the file manager.',
                'evidence' => $this->evidenceRows($suspicious, 12),
                'treat_action' => 'quarantine_suspicious_files',
                'treat_label' => 'Quarantine '.count($suspicious).' file(s)',
                'manual_steps' => [
                    'Open each path in the file manager if you want to inspect it before quarantine.',
                    'After quarantine, change the WordPress admin passwords and rotate the database password from the Database tab.',
                ],
                'source' => 'live',
            ];
        }

        $modified = $result['core']['modified'] ?? [];
        // A clean checksum pass outranks the mtime heuristic.
        $checksumsClean = (bool) ($result['core']['ran'] ?? false) && $modified === [];
        if ($wordpress && ! $checksumsClean && ($modified !== [] || $touched !== [])) {
            $list = $modified !== [] ? $modified : array_column($touched, 'path');
            $findings[] = [
                'id' => 'integrity_core_modified',
                'severity' => 'critical',
                'title' => count($list).' WordPress core file(s) differ from the official release',
                'summary' => $modified !== []
                    ? 'The checksum check found core files whose contents do not match WordPress.org. Malware commonly edits core files to survive; restoring core re-downloads exactly this version without touching wp-content.'
                    : 'Core files were modified after the last update was written. Restoring core re-downloads this version without touching wp-content.',
                'evidence' => array_map(fn ($p) => is_string($p) ? $p : (string) $p, array_slice($list, 0, 12)),
                'treat_action' => 'restore_wordpress_core',
                'treat_label' => 'Restore WordPress core',
                'manual_steps' => ['In Terminal: wp core download --force --skip-content --version=$(wp core version)'],
                'source' => 'live',
            ];
        }

        return $findings;
    }

    /**
     * Move the given hits to quarantine and write a manifest next to them.
     *
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return array{moved: list<string>, quarantine_dir: string}
     */
    public function quarantine(SSHService $ssh, ContainerDeployment $deployment, array $hits, ?array $onlyReasons = null): array
    {
        $onlyReasons ??= self::QUARANTINE_REASONS;
        $paths = [];
        foreach ($hits as $hit) {
            if (array_intersect($hit['reasons'], $onlyReasons) !== []) {
                $paths[] = $hit['path'];
            }
        }
        $stamp = now()->format('Ymd-His');
        $quarantineDir = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/'
            .trim((string) config('containers.integrity.quarantine_dir', '.quarantine'), '/').'/'.$stamp;
        if ($paths === []) {
            return ['moved' => [], 'quarantine_dir' => $quarantineDir];
        }

        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $moved = [];
        foreach (array_chunk($paths, 100) as $chunk) {
            $result = $ssh->execWithStatus($this->quarantineCommand($hostAppPath, $quarantineDir, $chunk), 300);
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '__MOVED__')) {
                    $moved[] = $line;
                }
            }
        }

        $manifest = [
            'service_id' => (int) $deployment->service_id,
            'container' => $deployment->container_name,
            'quarantined_at' => now()->toIso8601String(),
            'app_path' => $hostAppPath,
            'files' => array_values(array_filter($hits, fn ($h) => in_array($h['path'], $moved, true))),
        ];
        try {
            $ssh->upload((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $quarantineDir.'/manifest.json');
        } catch (\Throwable) {
            // The move already happened; a missing manifest only costs convenience.
        }

        return ['moved' => $moved, 'quarantine_dir' => $quarantineDir];
    }

    /**
     * Record the latest scan on the service so the nightly pass and Doctor
     * agree on what has already been reported. Paths that were just moved to
     * quarantine are dropped from the stored hits.
     *
     * @param  array{hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>, core: array{modified: list<string>, extra: list<string>, ran: bool}, scanned_at: string}  $result
     * @param  list<string>  $movedPaths
     * @return array{new_paths: list<string>, suspicious: int}
     */
    public function persist(Service $service, array $result, array $movedPaths = []): array
    {
        $hits = array_values(array_filter($result['hits'], fn ($h) => ! in_array($h['path'], $movedPaths, true)));
        $suspicious = array_values(array_filter($hits, fn ($h) => array_intersect($h['reasons'], self::QUARANTINE_REASONS) !== []));

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $previousPaths = array_column((array) ($meta['integrity_scan']['hits'] ?? []), 'path');
        $newPaths = array_values(array_diff(array_column($suspicious, 'path'), $previousPaths));

        $meta['integrity_scan'] = [
            'scanned_at' => $result['scanned_at'] ?? now()->toIso8601String(),
            'hits' => array_slice($hits, 0, 200),
            'core_modified' => array_slice((array) ($result['core']['modified'] ?? []), 0, 100),
            'core_checked' => (bool) ($result['core']['ran'] ?? false),
            'suspicious_count' => count($suspicious),
            'quarantined_count' => count($movedPaths),
        ];
        $service->forceFill(['service_meta' => $meta])->save();

        return ['new_paths' => $newPaths, 'suspicious' => count($suspicious)];
    }

    /**
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return list<string>
     */
    public function evidenceRows(array $hits, int $limit): array
    {
        $rows = [];
        foreach (array_slice($hits, 0, $limit) as $hit) {
            $rows[] = sprintf(
                '%s · %s · %s · %s',
                $hit['path'],
                implode(', ', array_map([$this, 'reasonLabel'], $hit['reasons'])),
                DirectAdminMailPullProgress::formatBytes((int) $hit['size']),
                $hit['mtime'] > 0 ? date('Y-m-d H:i', (int) $hit['mtime']) : 'unknown date',
            );
        }
        if (count($hits) > $limit) {
            $rows[] = '… and '.(count($hits) - $limit).' more';
        }

        return $rows;
    }

    public function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_PHP_IN_UPLOADS => 'PHP inside uploads',
            self::REASON_HTACCESS_PHP => '.htaccess enables PHP in uploads',
            self::REASON_MU_PLUGIN => 'unexpected must-use plugin',
            self::REASON_KNOWN_FAMILY => 'known webshell family',
            self::REASON_ROOT_PHP => 'PHP file WordPress never ships in its root',
            self::REASON_CORE_LOOKALIKE => 'name imitates a core file',
            self::REASON_RANDOM_NAME => 'random-looking name',
            self::REASON_PHP_IN_IMAGE => 'PHP code inside an image or text file',
            self::REASON_SIGNATURE => 'webshell signature in code',
            self::REASON_CORE_TOUCHED => 'core file changed after install',
            self::REASON_CORE_CHECKSUM => 'core checksum mismatch',
            self::REASON_CORE_EXTRA => 'not part of WordPress core',
            default => $reason,
        };
    }

    /**
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return list<array{path: string, reasons: list<string>, size: int, mtime: int}>
     */
    private function dedupe(array $hits): array
    {
        $byPath = [];
        foreach ($hits as $hit) {
            $path = ltrim($hit['path'], './');
            if (! isset($byPath[$path])) {
                $byPath[$path] = $hit;
                $byPath[$path]['path'] = $path;

                continue;
            }
            $byPath[$path]['reasons'] = array_values(array_unique(array_merge($byPath[$path]['reasons'], $hit['reasons'])));
        }

        return array_values($byPath);
    }
}
