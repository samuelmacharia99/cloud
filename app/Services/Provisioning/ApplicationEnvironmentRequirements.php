<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * Which environment variables a customer's application expects, and which of
 * those the platform has not already supplied.
 *
 * Two lists, because they mean different things. Keys read from a committed
 * example file are what the application *declares*: useful to show a customer
 * before they ever hit a problem, but plenty of them are optional. Keys taken
 * from a crash are what it *requires*: the application refused to start
 * without them, so those are what hold a deploy.
 *
 * Discovered keys are recorded, never filled in. Writing a blank value would
 * satisfy a "required string" check and turn a clean stop into an application
 * that boots looking healthy and misbehaves at runtime.
 */
class ApplicationEnvironmentRequirements
{
    public const REQUIRED_META_KEY = 'required_env_keys';

    public const DECLARED_META_KEY = 'declared_env_keys';

    /** @var list<string> */
    private const EXAMPLE_FILES = ['.env.example', '.env.sample', '.env.template', 'env.example'];

    /**
     * Keys the platform owns. An example file listing these is describing the
     * wiring we already do, not asking the customer for anything.
     *
     * The public API URLs are deliberately absent: the platform supplies those
     * only to a split stack, where the sibling API is reachable at a known
     * address. One container serving its own export has no such sibling, so
     * treating them as supplied hid the one setting the app needed most.
     * Anything already carrying a value is filtered out by unsatisfied().
     *
     * @var list<string>
     */
    private const PLATFORM_SUPPLIED_PREFIXES = ['DB_', 'MYSQL_', 'POSTGRES_', 'MONGO_', 'TALKSASA_'];

    /** @var list<string> */
    private const PLATFORM_SUPPLIED_KEYS = [
        'DATABASE_URL',
        'SYNC_DATABASE_URL',
        'INTERNAL_API_URL',
        'BACKEND_URL',
        'API_URL',
        'PORT',
        'APP_PORT',
        'SECRET_KEY',
        'PYTHONUNBUFFERED',
        'DATA_DIR',
        'COMPOSE_PROJECT_NAME',
    ];

    /**
     * Read the application's own example file and record every key it declares
     * that the platform does not already supply.
     *
     * @param  array<string, string>  $envVars
     * @return list<string>
     */
    public function discoverDeclared(
        SSHService $ssh,
        string $hostAppPath,
        string $applicationRoot,
        array $envVars,
    ): array {
        $declared = [];

        foreach ($this->searchRoots($hostAppPath, $applicationRoot) as $root) {
            foreach (self::EXAMPLE_FILES as $file) {
                $contents = $this->readFile($ssh, $root.'/'.$file);
                if ($contents === null) {
                    continue;
                }

                foreach ($this->parse($contents) as $key) {
                    $declared[$key] = true;
                }
            }
        }

        return $this->unsatisfied(array_keys($declared), $envVars);
    }

    /**
     * Keys that the platform neither supplies nor already has a value for.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $envVars
     * @return list<string>
     */
    public function unsatisfied(array $keys, array $envVars): array
    {
        $missing = [];

        foreach ($keys as $key) {
            if (! $this->isValidKey($key) || $this->isPlatformSupplied($key)) {
                continue;
            }

            if (trim((string) ($envVars[$key] ?? '')) !== '') {
                continue;
            }

            $missing[$key] = true;
        }

        return array_keys($missing);
    }

    /**
     * What the application asked for the last time it refused to start.
     *
     * This replaces the stored list rather than adding to it. Merging meant a
     * variable stayed "required" forever: a customer who deleted one from their
     * code still saw the platform demanding it, and could not tell which of the
     * names in front of them their application actually wanted. The most recent
     * crash is the only current answer.
     *
     * An empty list is not an answer, so it leaves the previous one alone; that
     * is a hold whose cause could not be read, not an application that suddenly
     * needs nothing.
     *
     * @param  list<string>  $keys
     */
    public function rememberRequired(Service $service, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->store($service, self::REQUIRED_META_KEY, $keys);
    }

    /**
     * @param  list<string>  $keys
     */
    public function rememberDeclared(Service $service, array $keys): void
    {
        $this->store($service, self::DECLARED_META_KEY, $keys);
    }

    /**
     * @return list<string>
     */
    public function required(Service $service): array
    {
        return $this->recall($service, self::REQUIRED_META_KEY);
    }

    /**
     * @return list<string>
     */
    public function declared(Service $service): array
    {
        return $this->recall($service, self::DECLARED_META_KEY);
    }

    /**
     * Required keys that still have no value. These are what hold a deploy.
     *
     * @return list<string>
     */
    public function outstandingRequired(Service $service, ?ContainerDeployment $deployment): array
    {
        return $this->unsatisfied($this->required($service), $this->environmentOf($deployment));
    }

    /**
     * Declared-but-unset keys the customer may still want to fill in, with the
     * blocking ones removed so the two lists never repeat each other.
     *
     * @return list<string>
     */
    public function outstandingDeclared(Service $service, ?ContainerDeployment $deployment): array
    {
        $required = $this->outstandingRequired($service, $deployment);

        return array_values(array_diff(
            $this->unsatisfied($this->declared($service), $this->environmentOf($deployment)),
            $required,
        ));
    }

    /**
     * The application started, so nothing is blocking it any more. Declared
     * keys are left alone; they remain useful suggestions.
     */
    public function forgetRequired(Service $service): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! array_key_exists(self::REQUIRED_META_KEY, $meta)) {
            return;
        }

        unset($meta[self::REQUIRED_META_KEY]);
        $service->update(['service_meta' => $meta]);
    }

    /**
     * Accepts KEY=value, `export KEY=value`, and bare `KEY=` placeholders.
     *
     * @return list<string>
     */
    public function parse(string $contents): array
    {
        $keys = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $keys[$matches[1]] = true;
        }

        return array_keys($keys);
    }

    /**
     * @param  list<string>  $keys
     */
    private function store(Service $service, string $metaKey, array $keys): void
    {
        $unique = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($this->isValidKey($key)) {
                $unique[$key] = true;
            }
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta[$metaKey] = array_keys($unique);
        $service->update(['service_meta' => $meta]);
    }

    /**
     * @return list<string>
     */
    private function recall(Service $service, string $metaKey): array
    {
        $stored = data_get($service->service_meta, $metaKey);
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($key): string => (string) $key, $stored),
            fn (string $key): bool => $this->isValidKey($key),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function environmentOf(?ContainerDeployment $deployment): array
    {
        return is_array($deployment?->env_values) ? $deployment->env_values : [];
    }

    /**
     * The application root first, then the repository root for monorepos that
     * keep one example file at the top.
     *
     * @return list<string>
     */
    private function searchRoots(string $hostAppPath, string $applicationRoot): array
    {
        $hostAppPath = rtrim($hostAppPath, '/');
        $applicationRoot = trim($applicationRoot, '/');

        $roots = [$hostAppPath];
        if ($applicationRoot !== '' && $applicationRoot !== '.') {
            array_unshift($roots, $hostAppPath.'/'.$applicationRoot);
        }

        return $roots;
    }

    private function isPlatformSupplied(string $key): bool
    {
        foreach (self::PLATFORM_SUPPLIED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return in_array($key, self::PLATFORM_SUPPLIED_KEYS, true);
    }

    private function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1;
    }

    private function readFile(SSHService $ssh, string $path): ?string
    {
        try {
            $contents = $ssh->exec('cat '.escapeshellarg($path).' 2>/dev/null || true', 15);
        } catch (\Throwable) {
            return null;
        }

        return trim($contents) === '' ? null : $contents;
    }
}
