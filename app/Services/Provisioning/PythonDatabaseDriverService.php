<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

/**
 * Which SQLAlchemy driver a Python application's database URL should name.
 *
 * SQLAlchemy reads a bare scheme as the synchronous driver, so `postgresql://`
 * loads psycopg2. An application built on `create_async_engine` refuses that
 * outright with "The asyncio extension requires an async driver to be used",
 * and uvicorn dies at import time having never served a request.
 *
 * The platform composes the URL, so the platform is what has to name the
 * driver. It does so only when both halves are true: the checkout actually asks
 * SQLAlchemy for an async engine, and it ships a driver that can answer.
 * Pinning a driver that is not installed trades this error for a
 * ModuleNotFoundError, so the requirements check is the whole safety of it.
 *
 * SYNC_DATABASE_URL keeps the bare form. Alembic and every other synchronous
 * caller cannot use an async driver either, and a stack that has both needs
 * both spellings.
 */
class PythonDatabaseDriverService
{
    public const DATABASE_URL_KEY = 'DATABASE_URL';

    public const SYNC_DATABASE_URL_KEY = 'SYNC_DATABASE_URL';

    /** The call that makes an async URL mandatory rather than merely possible. */
    public const ASYNC_ENGINE_CALL = 'create_async_engine';

    /**
     * Async drivers per URL scheme, most preferred first. A scheme absent from
     * here is one this platform will not rewrite.
     *
     * @var array<string, list<string>>
     */
    private const ASYNC_DRIVERS = [
        'postgresql' => ['asyncpg'],
        'postgres' => ['asyncpg'],
        'mysql' => ['asyncmy', 'aiomysql'],
        'mariadb' => ['asyncmy', 'aiomysql'],
    ];

    /** @var list<string> */
    private const DEPENDENCY_FILES = [
        'requirements.txt',
        'requirements/base.txt',
        'requirements/production.txt',
        'pyproject.toml',
    ];

    /** @var list<string> */
    private const GREP_EXCLUDES = ['.git', '.venv', 'venv', 'node_modules', '__pycache__', 'site-packages'];

    /**
     * Correct the database URLs in place for the checkout now on disk.
     *
     * @param  array<string, string>  $envVars  modified in place
     * @return array{changed: list<string>, driver: string|null, status: string, message: string}
     */
    public function align(
        SSHService $ssh,
        string $hostAppPath,
        string $applicationRoot,
        array &$envVars,
    ): array {
        $url = trim((string) ($envVars[self::DATABASE_URL_KEY] ?? ''));
        if ($url === '') {
            return $this->outcome([], null, 'no_url', 'No database URL to align.');
        }

        $scheme = $this->schemeOf($url);
        if ($scheme === null || ! isset(self::ASYNC_DRIVERS[$scheme])) {
            return $this->outcome([], null, 'unsupported_scheme', 'Database URL scheme is not one this platform pins a driver for.');
        }

        // Whatever else happens, the synchronous twin must stay synchronous.
        $changed = $this->alignSynchronousUrl($envVars, $url);

        $pinned = $this->pinnedDriverOf($url);
        if ($pinned !== null) {
            return $this->outcome($changed, $pinned, 'already_pinned', 'Database URL already names the '.$pinned.' driver.');
        }

        $roots = $this->searchRoots($hostAppPath, $applicationRoot);

        if (! $this->usesAsyncEngine($ssh, $roots)) {
            return $this->outcome($changed, null, 'synchronous', 'Application does not open an async database engine; leaving the URL synchronous.');
        }

        $dependencies = $this->readDependencies($ssh, $roots);
        if ($dependencies === null) {
            return $this->outcome($changed, null, 'unknown', 'Application opens an async database engine but its dependency list could not be read.');
        }

        $driver = $this->firstDeclaredDriver($dependencies, self::ASYNC_DRIVERS[$scheme]);
        if ($driver === null) {
            // Deliberately not guessed at. Naming a package the image does not
            // have swaps this crash for an import error one layer deeper, which
            // is harder for a customer to read, not easier.
            return $this->outcome(
                $changed,
                null,
                'driver_missing',
                'Application opens an async database engine but declares no async driver. Add '
                    .implode(' or ', self::ASYNC_DRIVERS[$scheme]).' to the dependency list.',
            );
        }

        $envVars[self::DATABASE_URL_KEY] = $this->withDriver($url, $scheme, $driver);
        $changed[] = self::DATABASE_URL_KEY;

        return $this->outcome(
            array_values(array_unique($changed)),
            $driver,
            'aligned',
            'Database URL now names the '.$driver.' driver, which the async engine needs.',
        );
    }

    /**
     * The bare form the application's synchronous callers want.
     */
    public function withoutDriver(string $url): string
    {
        return preg_replace('#^([A-Za-z0-9]+)\+[A-Za-z0-9_]+://#', '$1://', $url) ?? $url;
    }

    public function schemeOf(string $url): ?string
    {
        if (preg_match('#^([A-Za-z0-9]+)(?:\+[A-Za-z0-9_]+)?://#', $url, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    public function pinnedDriverOf(string $url): ?string
    {
        if (preg_match('#^[A-Za-z0-9]+\+([A-Za-z0-9_]+)://#', $url, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Whether a dependency list names a package, matched whole so that
     * "asyncpg" never comes back true for a differently named package that
     * merely contains it.
     */
    public function declaresPackage(string $dependencies, string $package): bool
    {
        $pattern = '/(?<![A-Za-z0-9_.\-])'.preg_quote($package, '/').'(?![A-Za-z0-9_\-])/i';

        return preg_match($pattern, $dependencies) === 1;
    }

    /**
     * @param  list<string>  $candidates
     */
    public function firstDeclaredDriver(string $dependencies, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->declaresPackage($dependencies, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function withDriver(string $url, string $scheme, string $driver): string
    {
        return preg_replace(
            '#^[A-Za-z0-9]+(?:\+[A-Za-z0-9_]+)?://#',
            $scheme.'+'.$driver.'://',
            $url,
            1
        ) ?? $url;
    }

    /**
     * SYNC_DATABASE_URL is written when it is blank, or when it carries a
     * driver suffix and so is not synchronous at all. A value the customer set
     * to something genuinely different is left alone.
     *
     * @param  array<string, string>  $envVars
     * @return list<string>
     */
    private function alignSynchronousUrl(array &$envVars, string $url): array
    {
        $bare = $this->withoutDriver($url);
        $current = trim((string) ($envVars[self::SYNC_DATABASE_URL_KEY] ?? ''));

        if ($current !== '' && $this->pinnedDriverOf($current) === null) {
            return [];
        }

        if ($current === $bare) {
            return [];
        }

        $envVars[self::SYNC_DATABASE_URL_KEY] = $bare;

        return [self::SYNC_DATABASE_URL_KEY];
    }

    /**
     * @param  list<string>  $roots
     */
    private function usesAsyncEngine(SSHService $ssh, array $roots): bool
    {
        $excludes = '';
        foreach (self::GREP_EXCLUDES as $directory) {
            $excludes .= ' --exclude-dir='.escapeshellarg($directory);
        }

        foreach ($roots as $root) {
            $command = 'grep -rsl --include='.escapeshellarg('*.py').$excludes
                .' -e '.escapeshellarg(self::ASYNC_ENGINE_CALL)
                .' '.escapeshellarg($root).' 2>/dev/null | head -n 1';

            try {
                if (trim($ssh->exec($command, 30)) !== '') {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $roots
     */
    private function readDependencies(SSHService $ssh, array $roots): ?string
    {
        $contents = '';

        foreach ($roots as $root) {
            foreach (self::DEPENDENCY_FILES as $file) {
                $read = $this->readFile($ssh, $root.'/'.$file);
                if ($read !== null) {
                    $contents .= $read."\n";
                }
            }
        }

        return trim($contents) === '' ? null : $contents;
    }

    private function readFile(SSHService $ssh, string $path): ?string
    {
        try {
            $contents = $ssh->exec('head -c 65536 '.escapeshellarg($path).' 2>/dev/null || true', 15);
        } catch (\Throwable) {
            return null;
        }

        return trim($contents) === '' ? null : $contents;
    }

    /**
     * The application root first, then the repository root, which is where a
     * monorepo keeps one shared dependency list.
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

    /**
     * @param  list<string>  $changed
     * @return array{changed: list<string>, driver: string|null, status: string, message: string}
     */
    private function outcome(array $changed, ?string $driver, string $status, string $message): array
    {
        return [
            'changed' => array_values($changed),
            'driver' => $driver,
            'status' => $status,
            'message' => $message,
        ];
    }
}
