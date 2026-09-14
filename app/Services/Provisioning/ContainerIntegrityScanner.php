<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Finds files that do not belong in a customer's application directory.
 *
 * The work happens on the container host in resources/tools/integrity-scan.py
 * (uploaded once per node, refreshed when its version changes), read-only,
 * with an official WordPress checksum manifest when one can be fetched so a
 * modified or foreign core file is named with certainty rather than guessed
 * from its name. Removal goes through ContainerIncidentService: zip, verify,
 * delete, record.
 */
class ContainerIntegrityScanner
{
    public const REASON_PHP_IN_UPLOADS = 'php_in_uploads';

    public const REASON_HTACCESS_PHP = 'htaccess_php_handler';

    public const REASON_MU_PLUGIN = 'unexpected_mu_plugin';

    public const REASON_KNOWN_FAMILY = 'known_webshell_family';

    public const REASON_ROOT_PHP = 'unexpected_root_php';

    public const REASON_CONTENT_PHP = 'unexpected_content_php';

    public const REASON_CORE_LOOKALIKE = 'core_lookalike';

    public const REASON_RANDOM_NAME = 'random_name';

    public const REASON_PHP_IN_IMAGE = 'php_in_image';

    public const REASON_SIGNATURE = 'signature';

    public const REASON_OBFUSCATED = 'obfuscated_code';

    public const REASON_EXPOSED = 'exposed_backup';

    public const REASON_CORE_TOUCHED = 'core_modified_after_install';

    public const REASON_CORE_CHECKSUM = 'core_checksum_mismatch';

    public const REASON_CORE_EXTRA = 'core_unexpected_file';

    public const REASON_CORE_MISSING = 'core_missing_file';

    public const REASON_DECEPTIVE = 'deceptive_name';

    /** Reasons confident enough to archive and remove without an operator's click. */
    public const AUTO_QUARANTINE_REASONS = [
        self::REASON_KNOWN_FAMILY,
        self::REASON_PHP_IN_UPLOADS,
        self::REASON_HTACCESS_PHP,
        self::REASON_PHP_IN_IMAGE,
        self::REASON_MU_PLUGIN,
        self::REASON_CORE_EXTRA,
        self::REASON_DECEPTIVE,
    ];

    /** Reasons the Quarantine button archives and removes. */
    public const QUARANTINE_REASONS = [
        self::REASON_KNOWN_FAMILY,
        self::REASON_PHP_IN_UPLOADS,
        self::REASON_HTACCESS_PHP,
        self::REASON_PHP_IN_IMAGE,
        self::REASON_MU_PLUGIN,
        self::REASON_ROOT_PHP,
        self::REASON_CONTENT_PHP,
        self::REASON_CORE_LOOKALIKE,
        self::REASON_RANDOM_NAME,
        self::REASON_SIGNATURE,
        self::REASON_OBFUSCATED,
        self::REASON_CORE_EXTRA,
        self::REASON_DECEPTIVE,
    ];

    /** Reasons that mean code an attacker planted, which also calls for new security keys. */
    public const WEBSHELL_REASONS = [
        self::REASON_KNOWN_FAMILY,
        self::REASON_DECEPTIVE,
        self::REASON_SIGNATURE,
        self::REASON_OBFUSCATED,
        self::REASON_PHP_IN_UPLOADS,
        self::REASON_PHP_IN_IMAGE,
        self::REASON_MU_PLUGIN,
    ];

    /** Core reasons: the file is restored from the official release, never deleted. */
    public const CORE_REASONS = [
        self::REASON_CORE_CHECKSUM,
        self::REASON_CORE_MISSING,
        self::REASON_CORE_TOUCHED,
    ];

    /** Files WordPress ships in its root. */
    public const CORE_ROOT_FILES = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config.php',
        'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
        'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
    ];

    public const TOOL_NAME = 'integrity-scan.py';

    public function __construct(
        private readonly WordPressCoreChecksumService $checksums,
        private readonly ContainerIncidentService $incidents,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Tool + commands
    |--------------------------------------------------------------------------
    */

    public function localToolPath(): string
    {
        return resource_path('tools/'.self::TOOL_NAME);
    }

    public function toolHostPath(): string
    {
        return app(WordPressContainerHardeningService::class)->toolsHostPath().'/'.self::TOOL_NAME;
    }

    public function localToolVersion(): int
    {
        $source = @file_get_contents($this->localToolPath());

        return $source !== false && preg_match('/TALKSASA_SCAN_VERSION=(\d+)/', $source, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Upload the scanner once per node and again whenever its version changes.
     */
    public function ensureScanTool(SSHService $ssh): void
    {
        $remote = $this->toolHostPath();
        $local = $this->localToolVersion();
        $probe = trim((string) $ssh->exec('grep -m1 -oE "TALKSASA_SCAN_VERSION=[0-9]+" '.escapeshellarg($remote).' 2>/dev/null || echo none', 15));
        if ($probe === 'TALKSASA_SCAN_VERSION='.$local && $local > 0) {
            return;
        }
        $source = file_get_contents($this->localToolPath());
        if ($source === false) {
            throw new \RuntimeException('integrity-scan.py is missing from the platform release.');
        }
        $ssh->exec('mkdir -p '.escapeshellarg(dirname($remote)).' && chmod 755 '.escapeshellarg(dirname($remote)), 15);
        $ssh->upload($source, $remote);
        $ssh->exec('chmod 755 '.escapeshellarg($remote), 15);
    }

    /**
     * @param  list<string>  $allowedMuPlugins
     */
    public function scanCommand(string $hostAppPath, bool $wordpress, ?string $manifestPath = null, array $allowedMuPlugins = []): string
    {
        $allow = $allowedMuPlugins !== [] ? $allowedMuPlugins : ['talksasa-admin-sso.php'];

        return 'python3 '.escapeshellarg($this->toolHostPath())
            .' --root '.escapeshellarg(rtrim($hostAppPath, '/'))
            .($wordpress ? ' --wordpress' : '')
            .($manifestPath !== null ? ' --manifest '.escapeshellarg($manifestPath) : '')
            .' --allow-mu '.escapeshellarg(implode(',', $allow))
            .' --max-file-kb '.max(64, (int) config('containers.integrity.max_file_kb', 2048))
            .' --max-hits '.max(20, (int) config('containers.integrity.max_hits_per_rule', 200))
            .' --time-budget '.max(30, (int) config('containers.integrity.time_budget_seconds', 240))
            .' 2>/dev/null';
    }

    /**
     * wp-cli fallback for a version whose release archive cannot be fetched.
     */
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
            if (count($parts) !== 4 || $parts[0] === '__SUMMARY__') {
                continue;
            }
            [$reason, $size, $mtime, $path] = $parts;
            // Only a leading "./" comes off: a trailing space is part of a deceptive file name.
            $path = (string) preg_replace('#^(\./)+#', '', rtrim($path, "\r\n"));
            if ($path === '' || preg_match('/^[a-z_]+$/', $reason) !== 1) {
                continue;
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
     * @return array<string, mixed>
     */
    public function parseSummary(string $output): array
    {
        if (preg_match('/^__SUMMARY__\t(\{.*\})\s*$/m', $output, $m) !== 1) {
            return ['truncated' => false, 'core' => ['verified' => false, 'version' => null], 'missing_summary' => true];
        }
        $decoded = json_decode($m[1], true);

        return is_array($decoded) ? $decoded : ['truncated' => false, 'core' => ['verified' => false, 'version' => null]];
    }

    /*
    |--------------------------------------------------------------------------
    | Scan
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{
     *     hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>,
     *     core: array{ran: bool, version: ?string, modified: list<string>, extra: list<string>, missing: list<string>, error: ?string},
     *     summary: array<string, mixed>,
     *     scanned_at: string
     * }
     */
    public function scan(SSHService $ssh, ContainerDeployment $deployment, bool $wordpress, bool $withChecksums = true): array
    {
        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $allow = (array) config('containers.integrity.mu_plugin_allowlist', ['talksasa-admin-sso.php']);

        $this->ensureScanTool($ssh);

        $manifestPath = null;
        $coreError = null;
        $version = null;
        if ($wordpress && $withChecksums) {
            try {
                $installed = $this->checksums->installedVersion($ssh, $hostAppPath);
                $version = $installed['version'];
                if ($version === null) {
                    $coreError = 'wp-includes/version.php is missing or unreadable';
                } else {
                    $manifestPath = $this->checksums->ensureManifestOnNode($ssh, $version, $installed['locale']);
                    if ($manifestPath === null) {
                        $coreError = 'WordPress.org did not return checksums for '.$version;
                    }
                }
            } catch (\Throwable $e) {
                $coreError = $e->getMessage();
                Log::warning('Core checksum manifest unavailable', ['container' => $deployment->container_name, 'error' => $e->getMessage()]);
            }
        }

        $output = $ssh->execWithStatus($this->scanCommand($hostAppPath, $wordpress, $manifestPath, $allow), 330);
        $hits = $this->parseScan($output['output']);
        $summary = $this->parseSummary($output['output']);
        if ($output['status'] !== 0 && $hits === [] && ($summary['missing_summary'] ?? false)) {
            throw new \RuntimeException('The integrity scanner did not run on the node: '.trim(mb_substr($output['output'], 0, 200)));
        }

        $byReason = fn (string $reason) => array_values(array_map(
            fn ($h) => $h['path'],
            array_filter($hits, fn ($h) => in_array($reason, $h['reasons'], true))
        ));
        $verified = (bool) ($summary['core']['verified'] ?? false);

        return [
            'hits' => $hits,
            'core' => [
                'ran' => $verified,
                'version' => $summary['core']['version'] ?? $version,
                'modified' => $byReason(self::REASON_CORE_CHECKSUM),
                'extra' => $byReason(self::REASON_CORE_EXTRA),
                'missing' => $byReason(self::REASON_CORE_MISSING),
                'error' => $verified ? null : $coreError,
            ],
            'summary' => $summary,
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Findings
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return list<array{path: string, reasons: list<string>, size: int, mtime: int}>
     */
    public function suspiciousHits(array $hits): array
    {
        return array_values(array_filter(
            $hits,
            fn ($h) => array_intersect($h['reasons'], self::QUARANTINE_REASONS) !== []
                && ! in_array(self::REASON_CORE_CHECKSUM, $h['reasons'], true)
        ));
    }

    /**
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return list<array{path: string, reasons: list<string>, size: int, mtime: int}>
     */
    public function coreHits(array $hits): array
    {
        return array_values(array_filter($hits, fn ($h) => array_intersect($h['reasons'], self::CORE_REASONS) !== []));
    }

    /**
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @return list<array{path: string, reasons: list<string>, size: int, mtime: int}>
     */
    public function exposedHits(array $hits): array
    {
        return array_values(array_filter(
            $hits,
            fn ($h) => in_array(self::REASON_EXPOSED, $h['reasons'], true)
                && array_intersect($h['reasons'], self::QUARANTINE_REASONS) === []
        ));
    }

    /**
     * @param  array{hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>, core: array<string, mixed>, summary?: array<string, mixed>}  $result
     * @return list<array<string, mixed>>
     */
    public function findings(array $result, bool $wordpress = true): array
    {
        $findings = [];
        $hits = $result['hits'];
        $suspicious = $this->suspiciousHits($hits);
        $core = $this->coreHits($hits);
        $exposed = $this->exposedHits($hits);
        $verified = (bool) ($result['core']['ran'] ?? false);
        $version = (string) ($result['core']['version'] ?? '');

        if ($suspicious !== []) {
            $webshell = array_filter($suspicious, fn ($h) => array_intersect($h['reasons'], self::WEBSHELL_REASONS) !== []);
            $findings[] = [
                'id' => 'integrity_suspicious_files',
                'severity' => 'critical',
                'title' => count($suspicious).' file(s) that do not belong to this application',
                'summary' => ($webshell !== [] ? 'Some of these carry webshell or obfuscated code. ' : '')
                    .'They sit where executable code never belongs, carry attack code, or have names WordPress never ships'
                    .($verified ? ' (core was verified against WordPress.org '.$version.')' : '').'. '
                    .'Quarantine zips them into an incident folder outside the site, removes the originals, and rotates the security keys so any stolen session cookie stops working. '
                    .'Real WordPress files are never deleted.',
                'evidence' => $this->evidenceRows($suspicious, 12),
                'treat_action' => 'quarantine_suspicious_files',
                'treat_label' => 'Quarantine '.count($suspicious).' file(s)',
                'manual_steps' => [
                    'Open a path in the file manager if you want to read it before quarantine.',
                    'Afterwards change every WordPress administrator password; a webshell usually means they were read.',
                ],
                'source' => 'live',
            ];
        }

        if ($wordpress && $core !== []) {
            $modified = array_values(array_filter($core, fn ($h) => in_array(self::REASON_CORE_CHECKSUM, $h['reasons'], true)));
            $missing = array_values(array_filter($core, fn ($h) => in_array(self::REASON_CORE_MISSING, $h['reasons'], true)));
            $touched = array_values(array_filter($core, fn ($h) => in_array(self::REASON_CORE_TOUCHED, $h['reasons'], true)));
            $count = count($modified) + count($missing) + ($verified ? 0 : count($touched));
            if ($count > 0) {
                $findings[] = [
                    'id' => 'integrity_core_modified',
                    'severity' => 'critical',
                    'title' => $verified
                        ? $count.' WordPress '.$version.' core file(s) differ from the official release'
                        : count($touched).' WordPress core file(s) changed after the last update',
                    'summary' => $verified
                        ? 'Checked against WordPress.org: '.count($modified).' modified, '.count($missing).' missing. Malware commonly edits core files to survive a plugin cleanup. '
                            .'Restore downloads the official '.$version.' release once per node, keeps a copy of the changed files in an incident folder, and writes back only these files. wp-content, uploads and wp-config.php are not touched.'
                        : 'The official checksums could not be fetched'.(($result['core']['error'] ?? null) ? ' ('.$result['core']['error'].')' : '').', so this is based on modification times. Restore replaces these files from the official release after keeping a copy.',
                    'evidence' => array_map(
                        fn ($h) => $h['path'].' · '.implode(', ', array_map([$this, 'reasonLabel'], $h['reasons'])).($h['size'] > 0 ? ' · '.DirectAdminMailPullProgress::formatBytes((int) $h['size']) : ''),
                        array_slice($verified ? array_merge($modified, $missing) : $touched, 0, 12)
                    ),
                    'treat_action' => 'restore_wordpress_core',
                    'treat_label' => 'Restore WordPress core',
                    'manual_steps' => ['In Terminal: wp core download --force --skip-content --version=$(wp core version)'],
                    'source' => 'live',
                ];
            }
        }

        if ($exposed !== []) {
            $findings[] = [
                'id' => 'integrity_exposed_files',
                'severity' => 'warning',
                'title' => count($exposed).' backup, dump or log file(s) can be downloaded by anyone',
                'summary' => 'Database dumps, site backups, old wp-config copies and logs inside the web root are served like any other file, so a guessed URL leaks passwords and content. '
                    .'Archiving zips them into an incident folder outside the site and removes them from the web root; the archive stays for you to download.',
                'evidence' => $this->evidenceRows($exposed, 12),
                'treat_action' => 'archive_exposed_files',
                'treat_label' => 'Archive '.count($exposed).' file(s)',
                'manual_steps' => ['Keep backups in the Backups tab, never inside the web root.'],
                'source' => 'live',
            ];
        }

        if ((bool) ($result['summary']['truncated'] ?? false)) {
            $findings[] = [
                'id' => 'integrity_scan_partial',
                'severity' => 'info',
                'title' => 'The file scan stopped early',
                'summary' => 'The site has more files than the scan budget allows in one run, so some rules were cut short. The nightly scan continues from a fresh budget.',
                'evidence' => [],
                'manual_steps' => [],
                'source' => 'live',
            ];
        }

        return $findings;
    }

    /*
    |--------------------------------------------------------------------------
    | Quarantine + persistence
    |--------------------------------------------------------------------------
    */

    /**
     * Archive and remove the hits that match $onlyReasons, through an incident.
     *
     * @param  list<array{path: string, reasons: list<string>, size: int, mtime: int}>  $hits
     * @param  list<string>|null  $onlyReasons
     * @return array{moved: list<string>, quarantine_dir: string, incident: ?string, bytes: int, skipped: list<string>}
     */
    public function quarantine(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        array $hits,
        ?array $onlyReasons = null,
        string $trigger = ContainerIncidentService::TRIGGER_DOCTOR,
        string $kind = ContainerIncidentService::KIND_MALWARE,
    ): array {
        $onlyReasons ??= self::QUARANTINE_REASONS;
        $selected = array_values(array_filter($hits, fn ($h) => array_intersect($h['reasons'], $onlyReasons) !== []));
        $protected = array_values(array_map(fn ($h) => $h['path'], array_filter($hits, fn ($h) => in_array(self::REASON_CORE_CHECKSUM, $h['reasons'], true))));
        // Root core files are always protected, whatever the scan said about them.
        foreach (self::CORE_ROOT_FILES as $file) {
            $protected[] = $file;
        }

        if ($selected === []) {
            return ['moved' => [], 'quarantine_dir' => '', 'incident' => null, 'bytes' => 0, 'skipped' => []];
        }

        $incident = $this->incidents->open(
            $ssh,
            $service,
            $deployment,
            $trigger,
            $kind,
            $selected,
            array_values(array_unique($protected)),
            [],
            true,
            // Backups are archives already and can be huge: move them, never re-zip.
            $kind === ContainerIncidentService::KIND_EXPOSED ? ContainerIncidentService::STORAGE_FILES : ContainerIncidentService::STORAGE_ZIP,
        );

        return [
            'moved' => $incident['removed'],
            'quarantine_dir' => $incident['dir'],
            'incident' => $incident['removed'] !== [] ? $incident['id'] : null,
            'bytes' => $incident['bytes'],
            'skipped' => $incident['skipped'],
        ];
    }

    /**
     * Record the latest scan on the service so the nightly pass and Doctor
     * agree on what has already been reported.
     *
     * @param  array{hits: list<array{path: string, reasons: list<string>, size: int, mtime: int}>, core: array<string, mixed>, summary?: array<string, mixed>, scanned_at?: string}  $result
     * @param  list<string>  $movedPaths
     * @return array{new_paths: list<string>, suspicious: int}
     */
    public function persist(Service $service, array $result, array $movedPaths = []): array
    {
        $hits = array_values(array_filter($result['hits'], fn ($h) => ! in_array($h['path'], $movedPaths, true)));
        $suspicious = $this->suspiciousHits($hits);

        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $previousPaths = array_column((array) ($meta['integrity_scan']['hits'] ?? []), 'path');
        $newPaths = array_values(array_diff(array_column($suspicious, 'path'), $previousPaths));

        $meta['integrity_scan'] = [
            'scanned_at' => $result['scanned_at'] ?? now()->toIso8601String(),
            'hits' => array_slice($hits, 0, 200),
            'core_modified' => array_slice((array) ($result['core']['modified'] ?? []), 0, 100),
            'core_missing' => array_slice((array) ($result['core']['missing'] ?? []), 0, 100),
            'core_checked' => (bool) ($result['core']['ran'] ?? false),
            'core_version' => $result['core']['version'] ?? null,
            'core_error' => $result['core']['error'] ?? null,
            'scan_partial' => (bool) ($result['summary']['truncated'] ?? false),
            'suspicious_count' => count($suspicious),
            'exposed_count' => count($this->exposedHits($hits)),
            'quarantined_count' => count($movedPaths),
        ];
        $service->forceFill(['service_meta' => $meta])->save();

        return ['new_paths' => $newPaths, 'suspicious' => count($suspicious)];
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

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
                $this->displayPath($hit['path']),
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

    /**
     * A path with its hidden characters spelled out, so "index.php" written
     * with a Cyrillic i cannot pass for the real file in the evidence list.
     */
    public function displayPath(string $path): string
    {
        if (preg_match('/[^\x21-\x7e]/', $path) !== 1) {
            return $path;
        }
        $codes = [];
        foreach (preg_split('//u', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (preg_match('/[^\x21-\x7e]/', $char) === 1) {
                $codes[] = sprintf('U+%04X', mb_ord($char, 'UTF-8'));
            }
        }
        $visible = preg_replace('/[^\x21-\x7e]/u', '?', $path) ?? $path;

        return $visible.' (hidden characters: '.implode(' ', array_unique($codes)).')';
    }

    public function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_PHP_IN_UPLOADS => 'PHP inside uploads',
            self::REASON_HTACCESS_PHP => '.htaccess enables PHP in uploads',
            self::REASON_MU_PLUGIN => 'unexpected must-use plugin',
            self::REASON_KNOWN_FAMILY => 'known webshell family',
            self::REASON_ROOT_PHP => 'PHP file WordPress never ships in its root',
            self::REASON_CONTENT_PHP => 'PHP file directly in wp-content',
            self::REASON_CORE_LOOKALIKE => 'name imitates a core file',
            self::REASON_RANDOM_NAME => 'random-looking name',
            self::REASON_PHP_IN_IMAGE => 'PHP code inside an image or text file',
            self::REASON_SIGNATURE => 'webshell signature in code',
            self::REASON_OBFUSCATED => 'obfuscated code',
            self::REASON_EXPOSED => 'backup, dump or log in the web root',
            self::REASON_CORE_TOUCHED => 'core file changed after install',
            self::REASON_CORE_CHECKSUM => 'differs from the official release',
            self::REASON_CORE_EXTRA => 'not part of WordPress core',
            self::REASON_CORE_MISSING => 'core file missing',
            self::REASON_DECEPTIVE => 'hidden characters in the file name',
            default => $reason,
        };
    }
}
