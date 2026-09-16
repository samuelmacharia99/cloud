<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Repairs for a PHP application the doctor found bouncing to its installer
 * while its database is fine. The app's own code says what it checks: a
 * marker file such as storage/installed, or an .env key such as
 * APP_INSTALLED. This reads that check and restores exactly what it asks
 * for, and touches nothing the code does not reference.
 */
class ContainerDoctorPhpSiteTreatments
{
    public const ACTION_MARK_INSTALLED = 'mark_php_app_installed';

    public function __construct(
        private DirectAdminToContainerMigrationService $migrator,
        private ContainerDoctorPhpSiteAnalyzer $analyzer,
        private ContainerIncidentService $incidents,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function markInstalled(Service $service): array
    {
        return $this->onHost($service, function (SSHService $ssh, ContainerDeployment $deployment, string $hostAppPath): array {
            $probe = $this->parseProbe((string) $ssh->exec($this->probeCommand($hostAppPath), 60));
            $plan = $this->plan($probe);
            $appRoot = $probe['root'] !== '' ? $probe['root'] : $hostAppPath;

            // A missing .env is the commonest "not installed" of all: apps of
            // this kind test that the file exists, and the exposed-files pass
            // used to archive it as a stray backup. Put it back before anything else.
            $envRestored = null;
            if (! $probe['env_present']) {
                $envRestored = $this->restoreEnvFile($ssh, $service, $deployment, $appRoot);
            }

            if ($plan['markers'] === [] && $plan['env_keys'] === [] && $envRestored === null) {
                $seen = array_slice($plan['evidence'], 0, 6);

                return [
                    'success' => false,
                    'message' => 'Could not tell what this app checks before deciding it is installed. '
                        .($seen === []
                            ? 'No code under app/, routes/ or the front controller references an installer redirect; the check may live in vendor code or a config file with the old host\'s credentials.'
                            : 'The install-related lines it found are: '.implode(' | ', $seen).'. Restore that marker or key by hand from the Files tab.'),
                ];
            }

            $created = [];
            if ($plan['markers'] !== []) {
                $output = (string) $ssh->exec($this->applyMarkersCommand($appRoot, $plan['markers']), 60);
                preg_match_all('/^CREATED=(.+)$/m', $output, $m);
                $created = $m[1];
            }

            $envSet = [];
            if ($plan['env_keys'] !== []) {
                $values = [];
                foreach ($plan['env_keys'] as $key) {
                    $values[$key] = 'true';
                }
                $this->migrator->rewriteAppEnvDatabase($ssh, $appRoot, $values);
                $envSet = array_keys($values);
            }

            $cacheNote = '';
            if ($deployment->isRunning()) {
                try {
                    app(LaravelAppInitializationService::class)->dockerExecPublic(
                        $ssh,
                        $deployment->container_name,
                        $this->cacheClearScript($appRoot, $hostAppPath),
                        120
                    );
                } catch (\Throwable $e) {
                    $cacheNote = ' Config cache could not be cleared ('.mb_substr($e->getMessage(), 0, 120).'); restart the app if it still redirects.';
                }
            }

            $after = '';
            $loopback = $deployment->loopbackUrl();
            if ($deployment->isRunning() && is_string($loopback) && $loopback !== '') {
                try {
                    $page = $this->analyzer->classify((string) $ssh->exec($this->analyzer->bodyProbeCommand($loopback, $deployment->probeHostHeader()), 30, false));
                    if ($page['status'] !== null) {
                        $after = $page['installer']
                            ? ' The homepage still lands on '.$page['final_path'].' (HTTP '.$page['status'].'); the app checks something else as well.'
                            : ' The homepage now answers HTTP '.$page['status'].' at '.$page['final_path'].'.';
                    }
                } catch (\Throwable) {
                }
            }

            $parts = [];
            if ($envRestored !== null) {
                $parts[] = $envRestored;
            }
            if ($created !== []) {
                $parts[] = 'created '.implode(', ', $created);
            }
            if ($envSet !== []) {
                $parts[] = 'set '.implode(', ', array_map(fn (string $k): string => $k.'=true', $envSet)).' in .env';
            }
            if ($parts === []) {
                $parts[] = 'the marker the code checks was already present';
            }

            Log::info('Doctor marked a PHP app installed', ['service_id' => $service->id, 'created' => $created, 'env' => $envSet]);

            return [
                'success' => true,
                'message' => 'Marked as installed: '.implode('; ', $parts).'.'.$cacheNote.$after,
            ];
        });
    }

    /**
     * Bash run on the node against the bind-mounted app: finds the code that
     * redirects to an installer and prints the install-related checks in it,
     * plus the config keys that map to .env, for plan() to read.
     */
    public function probeCommand(string $hostAppPath): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));

        return 'root='.$root.'; [ -d "$root" ] || exit 0; app="$root"; '
            .'if [ -f "$root/backend/artisan" ]; then app="$root/backend"; fi; '
            .'echo "ROOT=$app"; cd "$app" || exit 0; '
            .'[ -f .env ] && echo "ENV=present" || echo "ENV=missing"; '
            .'dirs=""; for d in app routes bootstrap config application system includes core src index.php public/index.php; do [ -e "$d" ] && dirs="$dirs $d"; done; '
            .'[ -z "$dirs" ] && exit 0; '
            .'files=$(grep -rIl --include="*.php" -i "install" $dirs 2>/dev/null | grep -vE "(^|/)(vendor|node_modules|storage|lang|views)/" | head -80); '
            .'for f in $files; do '
            .'  if grep -qE "redirect\(|Redirect::|header\([\'\"]Location|->to\(|RedirectResponse" "$f"; then '
            .'    echo "FILE=$f"; '
            .'    grep -nE "file_exists|is_file|File::exists|Storage::|exists\(|env\(|getenv\(|config\(|hasTable|DB::table|redirect|Location" "$f" | grep -iE "install" | head -40 | sed "s|^|LINE=$f:|"; '
            .'    case "$(basename "$f" | tr A-Z a-z)" in *install*) grep -nE "file_exists|is_file|File::exists|base_path|\.env" "$f" | head -20 | sed "s|^|CHECK=$f:|";; esac; '
            .'  fi; '
            .'done; '
            .'grep -rnE "=> *env\([\'\"][A-Z0-9_]+[\'\"]" config 2>/dev/null | grep -i install | head -50 | sed "s|^|CONFIGENV=|"; '
            .'true';
    }

    /**
     * @return array{root: string, env_present: bool, files: list<string>, lines: list<array{file: string, text: string}>, checks: list<array{file: string, text: string}>, config_env: array<string, string>}
     */
    public function parseProbe(string $output): array
    {
        $result = ['root' => '', 'env_present' => true, 'files' => [], 'lines' => [], 'checks' => [], 'config_env' => []];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $raw) {
            if (str_starts_with($raw, 'ROOT=')) {
                $result['root'] = trim(substr($raw, 5));
            } elseif (str_starts_with($raw, 'ENV=')) {
                $result['env_present'] = trim(substr($raw, 4)) === 'present';
            } elseif (str_starts_with($raw, 'CHECK=')) {
                if (preg_match('/^CHECK=([^:]+):(\d+):(.*)$/', $raw, $m) === 1) {
                    $result['checks'][] = ['file' => $m[1], 'text' => trim($m[3])];
                }
            } elseif (str_starts_with($raw, 'FILE=')) {
                $result['files'][] = trim(substr($raw, 5));
            } elseif (str_starts_with($raw, 'LINE=')) {
                // LINE=<file>:<lineno>:<text>
                if (preg_match('/^LINE=([^:]+):(\d+):(.*)$/', $raw, $m) === 1) {
                    $result['lines'][] = ['file' => $m[1], 'text' => trim($m[3])];
                }
            } elseif (str_starts_with($raw, 'CONFIGENV=')) {
                // CONFIGENV=config/app.php:12:    'installed' => env('APP_INSTALLED', false),
                if (preg_match('/^CONFIGENV=config\/([A-Za-z0-9_-]+)\.php:\d+:\s*[\'"]([A-Za-z0-9_.-]+)[\'"]\s*=>\s*env\([\'"]([A-Z0-9_]+)[\'"]/', $raw, $m) === 1) {
                    $result['config_env'][strtolower($m[1].'.'.$m[2])] = $m[3];
                }
            }
        }

        return $result;
    }

    /**
     * What the code checks: marker files to create (relative to the app root)
     * and .env keys to set. Only install-related references count, so a
     * file_exists('.env') next to the redirect never turns into a file.
     *
     * @param  array{root: string, env_present: bool, files: list<string>, lines: list<array{file: string, text: string}>, checks: list<array{file: string, text: string}>, config_env: array<string, string>}  $probe
     * @return array{markers: list<string>, env_keys: list<string>, needs_env_file: bool, evidence: list<string>}
     */
    public function plan(array $probe): array
    {
        $markers = [];
        $envKeys = [];
        $evidence = [];
        $needsEnvFile = false;

        // An install middleware that tests the .env file itself: IsInstalled.php
        // with base_path('.env') or an $envPath it assigned from it.
        foreach ($probe['checks'] ?? [] as $check) {
            if (preg_match('/base_path\s*\(\s*[\'"]\.env[\'"]\s*\)|[\'"]\.env[\'"]|\$env(?:_?path|File|_?file)\b/i', $check['text']) === 1) {
                $needsEnvFile = true;
                $evidence[] = $check['file'].': '.mb_substr($check['text'], 0, 140);
            }
        }

        foreach ($probe['lines'] as $line) {
            $text = $line['text'];
            $dir = str_contains($line['file'], '/') ? dirname($line['file']) : '.';
            $evidence[] = $line['file'].': '.mb_substr($text, 0, 140);

            // One level of nesting so file_exists(storage_path('installed')) keeps its helper call whole.
            if (preg_match('/(?:file_exists|is_file|File::exists|Storage::exists|Storage::disk\([^)]*\)->exists)\s*\(\s*((?:[^()]|\([^()]*\))+)\s*\)/i', $text, $m) === 1) {
                $marker = $this->resolveMarkerPath($m[1], $dir, str_contains($m[0], 'Storage::'));
                if ($marker !== null && stripos($marker, 'install') !== false) {
                    $markers[] = $marker;
                }
            }

            if (preg_match_all('/(?:env|getenv)\s*\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $text, $m) > 0) {
                foreach ($m[1] as $key) {
                    if (stripos($key, 'install') !== false) {
                        $envKeys[] = $key;
                    }
                }
            }

            if (preg_match_all('/config\s*\(\s*[\'"]([a-z0-9_.-]+)[\'"]/i', $text, $m) > 0) {
                foreach ($m[1] as $configKey) {
                    $mapped = $probe['config_env'][strtolower($configKey)] ?? null;
                    if ($mapped !== null && stripos($configKey, 'install') !== false) {
                        $envKeys[] = $mapped;
                    }
                }
            }
        }

        return [
            'markers' => array_values(array_unique($markers)),
            'env_keys' => array_values(array_unique($envKeys)),
            'needs_env_file' => $needsEnvFile,
            'evidence' => $evidence,
        ];
    }

    /**
     * Put a missing .env back: from the incident that archived it when there
     * is one, otherwise rendered from the deployment's environment values so
     * the app at least reads the platform database and app settings.
     *
     * @return string|null what was done, for the treatment message
     */
    public function restoreEnvFile(SSHService $ssh, Service $service, ContainerDeployment $deployment, string $appRoot): ?string
    {
        $target = rtrim($appRoot, '/').'/.env';

        foreach ($this->incidents->incidentsFor($service) as $incident) {
            $paths = array_map('strval', (array) ($incident['paths'] ?? []));
            if (! in_array('.env', $paths, true) || ($incident['storage'] ?? '') !== ContainerIncidentService::STORAGE_FILES) {
                continue;
            }
            $id = (string) ($incident['id'] ?? '');
            if (preg_match('/^\d{8}-\d{6}-[a-z0-9]{6}$/', $id) !== 1) {
                continue;
            }
            $source = $this->incidents->incidentsDir($deployment).'/'.$id.'/'.ContainerIncidentService::FILES_DIR.'/.env';
            $output = (string) $ssh->exec($this->restoreEnvFromIncidentCommand($source, $target), 30);
            if (str_contains($output, 'RESTORED=.env')) {
                return 'restored .env from incident '.$id.' (the exposed-files pass had archived it)';
            }
        }

        $values = is_array($deployment->env_values) ? $deployment->env_values : [];
        if ($values === []) {
            return null;
        }
        $content = app(LaravelAppInitializationService::class)->ensureAppKeyInEnvContent(
            $this->migrator->mergeEnvAssignments('', array_map('strval', $values))
        );
        $ssh->upload($content, $target);
        $ssh->exec('chown 33:33 '.escapeshellarg($target).' 2>/dev/null; chmod 640 '.escapeshellarg($target).'; true', 15);

        return 'wrote a fresh .env from the deployment\'s environment values (no archived copy was found; app-specific keys the old host had are not in it)';
    }

    public function restoreEnvFromIncidentCommand(string $source, string $target): string
    {
        $s = escapeshellarg($source);
        $t = escapeshellarg($target);

        return 'if [ -f '.$s.' ] && [ ! -e '.$t.' ]; then cp -a '.$s.' '.$t.' && { chown 33:33 '.$t.' 2>/dev/null || true; } && chmod 640 '.$t.' && echo "RESTORED=.env"; '
            .'elif [ -e '.$t.' ]; then echo "PRESENT=.env"; else echo "MISSING=source"; fi; true';
    }

    /**
     * Turn the expression inside file_exists(...) into a path relative to the
     * app root, or null when it is not a shape we understand.
     */
    public function resolveMarkerPath(string $expression, string $fileDir, bool $storageDisk): ?string
    {
        $expression = trim($expression);

        if (preg_match('/^(storage_path|base_path|public_path|app_path|resource_path)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $expression, $m) === 1) {
            $prefix = match ($m[1]) {
                'storage_path' => 'storage/',
                'public_path' => 'public/',
                'app_path' => 'app/',
                'resource_path' => 'resources/',
                default => '',
            };

            return $this->tidy($prefix.ltrim($m[2], '/'));
        }

        if (preg_match('/^__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $expression, $m) === 1) {
            return $this->tidy(($fileDir === '.' ? '' : $fileDir.'/').ltrim($m[1], '/'));
        }

        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $expression, $m) === 1) {
            $literal = $m[1];
            if ($storageDisk) {
                return $this->tidy('storage/app/'.ltrim($literal, '/'));
            }
            if (str_starts_with($literal, '/')) {
                return null;
            }

            return $this->tidy(($fileDir === '.' ? '' : $fileDir.'/').$literal);
        }

        return null;
    }

    private function tidy(string $path): ?string
    {
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    /**
     * @param  list<string>  $markers  relative to the app root
     */
    public function applyMarkersCommand(string $appRoot, array $markers): string
    {
        $parts = ['cd '.escapeshellarg(rtrim($appRoot, '/')).' || exit 1'];
        foreach ($markers as $marker) {
            $m = escapeshellarg($marker);
            // Ownership is best effort: the node runs this as root, but the file
            // existing is what the app checks, so a chown failure must not hide it.
            $parts[] = 'mkdir -p "$(dirname '.$m.')" && if [ -e '.$m.' ]; then echo "PRESENT='.$marker.'"; else printf "installed %s\n" "$(date -u +%FT%TZ)" > '.$m.' && { chown 33:33 '.$m.' 2>/dev/null || true; } && chmod 644 '.$m.' && echo "CREATED='.$marker.'"; fi';
        }

        return implode('; ', $parts).'; true';
    }

    /**
     * Inside the container: drop Laravel's cached config so a new .env key is
     * read, on whichever root holds artisan. Harmless for non-Laravel apps.
     */
    public function cacheClearScript(string $appRoot, string $hostAppPath): string
    {
        $relative = trim(str_replace(rtrim($hostAppPath, '/'), '', rtrim($appRoot, '/')), '/');
        $inside = '/app'.($relative !== '' ? '/'.$relative : '');

        return 'cd '.escapeshellarg($inside).' 2>/dev/null || exit 0; '
            .'rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null; '
            .'if [ -f artisan ]; then php artisan config:clear >/dev/null 2>&1 || true; php artisan route:clear >/dev/null 2>&1 || true; fi; true';
    }

    /**
     * @param  callable(SSHService, ContainerDeployment, string): array{success: bool, message: string}  $work
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
            Log::warning('PHP site doctor treatment failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Repair failed: '.$e->getMessage()];
        } finally {
            $ssh->disconnect();
        }
    }
}
