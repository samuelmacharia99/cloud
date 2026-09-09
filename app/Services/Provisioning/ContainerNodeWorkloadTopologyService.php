<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerNodeWorkloadTopologyService
{
    public const SCHEMA = 1;

    public const BACKEND_PORT = 8000;

    public const FRONTEND_PORT = 3000;

    /** @var list<string> */
    private const SPLIT_BACKEND_SLUGS = ['nodejs', 'python', 'ruby', 'go'];

    /** @var list<string> */
    private const BACKEND_CANDIDATES = ['.', 'apps/api', 'api', 'backend', 'server', 'apps/server', 'packages/api'];

    /**
     * Preferred browser-app directories. Metro-only Expo at apps/mobile is not
     * treated as Vite/Next during auto-scan; a single Expo app is still hosted
     * as expo-web when the customer asked for a browser frontend.
     *
     * @var list<string>
     */
    private const FRONTEND_CANDIDATES = [
        'apps/web',
        'packages/web',
        'frontend',
        'web',
        'client',
        'apps/frontend',
        'apps/app',
        'apps/site',
        'www',
        'site',
        'apps/mobile',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        Service $service,
        SSHService $ssh,
        string $hostAppPath,
        ?string $backendOverride = null,
        ?string $frontendOverride = null,
    ): array {
        $slug = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? ''));
        if (! in_array($slug, self::SPLIT_BACKEND_SLUGS, true)) {
            return [
                'schema' => self::SCHEMA,
                'topology' => 'single',
                'selection_source' => 'stack',
            ];
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $frontend = strtolower((string) ($meta['frontend'] ?? 'none'));
        $framework = (string) ($meta['framework'] ?? 'other');
        $discovered = $this->discoverPackageRoots($ssh, $hostAppPath);
        $skippedMobile = $this->mobileRoots($ssh, $hostAppPath, $discovered);
        $backendCandidates = $this->uniqueRoots([
            ...self::BACKEND_CANDIDATES,
            ...$this->discoverBackendRoots($ssh, $hostAppPath),
            ...$discovered,
        ]);
        $frontendCandidates = $this->uniqueRoots([...self::FRONTEND_CANDIDATES, ...$discovered]);

        if ($frontend === 'none') {
            if ($slug !== 'nodejs') {
                return [
                    'schema' => self::SCHEMA,
                    'topology' => 'single',
                    'selection_source' => 'stack',
                ];
            }
            $backendRoot = $this->resolveRoot(
                $ssh,
                $hostAppPath,
                'backend',
                $backendOverride,
                $backendCandidates,
                fn (string $root): bool => $this->isBackendAt($ssh, $hostAppPath, $root, $slug, $framework),
                required: $backendOverride !== null && trim((string) $backendOverride) !== '',
            );
            if ($backendRoot === null) {
                return [
                    'schema' => self::SCHEMA,
                    'topology' => 'single',
                    'selection_source' => 'stack',
                ];
            }

            return $this->apiOnlyTopology(
                $service,
                $ssh,
                $hostAppPath,
                $backendRoot,
                $skippedMobile,
                'stack',
                $frontend,
            );
        }

        if (! in_array($frontend, ['nextjs', 'vite-spa'], true)) {
            throw new \DomainException("The selected frontend '{$frontend}' is not supported by the split web runtime.");
        }

        $effectiveFrontendOverride = $frontendOverride;

        $backendRoot = $this->resolveRoot(
            $ssh,
            $hostAppPath,
            'backend',
            $backendOverride,
            $backendCandidates,
            fn (string $root): bool => $this->isBackendAt($ssh, $hostAppPath, $root, $slug, $framework),
            required: true,
        );
        if ($effectiveFrontendOverride !== null && trim($effectiveFrontendOverride) !== '') {
            try {
                if ($this->sanitizeRelativeRoot($effectiveFrontendOverride) === $backendRoot) {
                    $effectiveFrontendOverride = null;
                }
            } catch (\DomainException) {
                // Invalid override is rejected in resolveBrowserFrontend.
            }
        }

        $browser = $this->resolveBrowserFrontend(
            $ssh,
            $hostAppPath,
            $frontend,
            $effectiveFrontendOverride,
            array_values(array_filter(
                $frontendCandidates,
                fn (string $root): bool => $root !== $backendRoot,
            )),
        );
        if ($browser === null) {
            $browser = $this->resolveExpoWebFallback($ssh, $hostAppPath, $skippedMobile, $backendRoot);
        }

        if ($browser !== null && $backendRoot !== $browser['root']) {
            $hostedFrontend = $browser['root'];
            $skippedMobile = array_values(array_filter(
                $skippedMobile,
                fn (string $root): bool => $root !== $hostedFrontend,
            ));

            return $this->splitTopology(
                $ssh,
                $hostAppPath,
                $backendRoot,
                $browser,
                ($backendOverride || $effectiveFrontendOverride) ? 'manual' : 'auto',
                $framework,
                $skippedMobile,
                $slug,
            );
        }

        return $this->apiOnlyTopology(
            $service,
            $ssh,
            $hostAppPath,
            $backendRoot,
            $skippedMobile,
            'auto_api',
            $frontend,
            $browser !== null && $backendRoot === $browser['root']
                ? 'The API and the selected frontend are the same directory ('.$backendRoot.'). This host runs that one application; a split stack needs a separate web app such as apps/web.'
                : null,
        );
    }

    /**
     * True when this service should expose a dedicated API hostname: the stack
     * has no browser frontend, including when a mobile app was skipped.
     */
    public static function isApiOnly(Service $service): bool
    {
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs') {
            return false;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (data_get($meta, 'node_workloads.topology') === 'split_web_api') {
            return false;
        }

        if ((string) data_get($meta, 'frontend', 'none') === 'none') {
            return true;
        }

        return data_get($meta, 'node_workloads.selection_source') === 'auto_api';
    }

    /**
     * @return array<string, mixed>
     */
    public function persist(Service $service, array $topology): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['node_workloads'] = $topology;
        $backendRoot = data_get($topology, 'backend.root');
        if (is_string($backendRoot) && trim($backendRoot) !== '' && empty($meta['node_backend_root'])) {
            $meta['node_backend_root'] = $backendRoot;
        }

        $frontendRoot = data_get($topology, 'frontend.root');
        if (is_string($frontendRoot) && trim($frontendRoot) !== '') {
            $meta['node_frontend_root'] = $frontendRoot;
        } else {
            unset($meta['node_frontend_root']);
        }

        $service->update(['service_meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $browser
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>  $skippedMobile
     */
    private function splitTopology(
        SSHService $ssh,
        string $hostAppPath,
        string $backendRoot,
        array $browser,
        string $selectionSource,
        string $framework,
        array $skippedMobile = [],
        string $backendSlug = 'nodejs',
    ): array {
        $backendPackage = $this->packageAt($ssh, $hostAppPath, $backendRoot);
        $frontendPackage = $this->packageAt($ssh, $hostAppPath, $browser['root']);
        $versionService = app(ContainerNodeVersionService::class);
        $runtimeService = app(ContainerApplicationRuntimeService::class);
        $backendRuntime = $backendSlug === 'nodejs'
            ? $runtimeService->detectNodeRuntimeAt(
                $ssh,
                $hostAppPath,
                $backendRoot,
                self::BACKEND_PORT,
                includeBootstrap: false,
            )
            : $runtimeService->detectRuntimeAt(
                $ssh,
                $hostAppPath,
                $backendRoot,
                $backendSlug,
                self::BACKEND_PORT,
                includeNodeBootstrap: false,
            );
        $frontendRuntime = app(ContainerApplicationRuntimeService::class)->detectNodeRuntimeAt(
            $ssh,
            $hostAppPath,
            $browser['root'],
            self::FRONTEND_PORT,
            includeBootstrap: false,
        );

        $notes = [];
        if ($skippedMobile !== []) {
            $notes[] = 'Skipped Expo/React Native at '.implode(', ', $skippedMobile)
                .'. The browser app at '.$browser['root'].' is what this host publishes.';
        }

        return [
            'schema' => self::SCHEMA,
            'topology' => 'split_web_api',
            'selection_source' => $selectionSource,
            'backend_slug' => $backendSlug,
            'framework' => $framework,
            'frontend_type' => $browser['type'],
            'backend' => $this->workloadPayload($backendRoot, self::BACKEND_PORT, $backendRuntime, $backendPackage, $versionService),
            'frontend' => $this->workloadPayload($browser['root'], self::FRONTEND_PORT, $frontendRuntime, $frontendPackage, $versionService),
            'skipped_mobile' => $skippedMobile,
            'notes' => $notes,
            'resolved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $mobileRoots
     * @return array<string, mixed>
     */
    private function apiOnlyTopology(
        Service $service,
        SSHService $ssh,
        string $hostAppPath,
        string $backendRoot,
        array $mobileRoots,
        string $selectionSource,
        string $requestedFrontend,
        ?string $extraNote = null,
    ): array {
        $backendPackage = $this->packageAt($ssh, $hostAppPath, $backendRoot);
        $versionService = app(ContainerNodeVersionService::class);
        $backendRuntime = app(ContainerApplicationRuntimeService::class)->detectNodeRuntimeAt(
            $ssh,
            $hostAppPath,
            $backendRoot,
            (int) ($service->effectiveContainerTemplate()?->default_port ?? 3000),
            includeBootstrap: false,
        );
        $notes = [];
        if ($extraNote !== null && trim($extraNote) !== '') {
            $notes[] = $extraNote;
        }
        if ($mobileRoots !== []) {
            $notes[] = 'Skipped Expo/React Native at '.implode(', ', $mobileRoots)
                .'. The API at '.$backendRoot.' is what this host runs; build the mobile app with a mobile build service.';
        } elseif ($requestedFrontend !== 'none' && $notes === []) {
            $notes[] = 'No browser frontend matched the selected stack. The API at '.$backendRoot.' is running on this host.';
        }

        return [
            'schema' => self::SCHEMA,
            'topology' => 'single',
            'selection_source' => $selectionSource,
            'frontend_type' => 'none',
            'requested_frontend' => $requestedFrontend,
            'backend' => $this->workloadPayload(
                $backendRoot,
                (int) ($service->effectiveContainerTemplate()?->default_port ?? 3000),
                $backendRuntime,
                $backendPackage,
                $versionService,
            ),
            'skipped_mobile' => $mobileRoots,
            'notes' => $notes,
            'resolved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $package
     * @return array<string, mixed>
     */
    private function workloadPayload(
        string $root,
        int $port,
        ApplicationRuntime $runtime,
        ?array $package,
        ContainerNodeVersionService $versionService,
    ): array {
        return [
            'root' => $root,
            'port' => $port,
            'runtime_source' => $runtime->source,
            'runtime_label' => $runtime->label,
            'working_directory' => $runtime->containerWorkdir,
            'start_command' => $runtime->command,
            'node_engine' => $package === null
                ? null
                : $versionService->constraintFromPackageJson(
                    json_encode($package, JSON_THROW_ON_ERROR),
                ),
        ];
    }

    /**
     * @param  list<string>  $candidates
     * @param  (callable(string): bool)  $accepts
     */
    private function resolveRoot(
        SSHService $ssh,
        string $hostAppPath,
        string $role,
        ?string $override,
        array $candidates,
        callable $accepts,
        bool $required,
    ): ?string {
        if ($override !== null && trim($override) !== '') {
            $root = $this->sanitizeRelativeRoot($override);
            if (! $accepts($root)) {
                throw new \DomainException("The selected {$role} root '{$root}' is not a valid {$role} application.");
            }

            return $root;
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            if ($accepts($candidate)) {
                $matches[] = $candidate;
            }
        }
        if ($matches === []) {
            if (! $required) {
                return null;
            }

            throw new \DomainException(
                "No {$role} application matched the selected stack. Choose its repository directory in Advanced roots."
            );
        }
        if (count($matches) > 1) {
            throw new \DomainException(
                'Multiple '.$role.' applications were detected ('.implode(', ', $matches)
                .'). Choose the intended directory in Advanced roots.'
            );
        }

        return $matches[0];
    }

    /**
     * @param  list<string>  $candidates
     * @return array{root: string, type: string}|null
     */
    private function resolveBrowserFrontend(
        SSHService $ssh,
        string $hostAppPath,
        string $requested,
        ?string $override,
        array $candidates,
    ): ?array {
        if ($override !== null && trim($override) !== '') {
            $root = $this->sanitizeRelativeRoot($override);
            $package = $this->packageAt($ssh, $hostAppPath, $root);
            if ($package === null) {
                throw new \DomainException("The selected frontend root '{$root}' is not a valid frontend application.");
            }
            $kind = $this->explicitBrowserKind($package);
            if ($kind === null) {
                throw new \DomainException("The selected frontend root '{$root}' is not a valid frontend application.");
            }

            return ['root' => $root, 'type' => $kind];
        }

        $preferred = [];
        $other = [];
        foreach ($candidates as $candidate) {
            $package = $this->packageAt($ssh, $hostAppPath, $candidate);
            if ($package === null) {
                continue;
            }
            $kind = $this->browserFrontendKind($package);
            if ($kind === null) {
                continue;
            }
            if ($kind === $requested) {
                $preferred[] = ['root' => $candidate, 'type' => $kind];
            } else {
                $other[] = ['root' => $candidate, 'type' => $kind];
            }
        }

        $matches = $preferred !== [] ? $preferred : $other;
        if ($matches === []) {
            return null;
        }
        if (count($matches) > 1) {
            throw new \DomainException(
                'Multiple frontend applications were detected ('.implode(', ', array_column($matches, 'root'))
                .'). Choose the intended directory in Advanced roots.'
            );
        }

        return $matches[0];
    }

    public function sanitizeRelativeRoot(string $root): string
    {
        $root = trim(str_replace('\\', '/', $root), '/');
        if ($root === '.') {
            return '.';
        }
        if ($root === ''
            || str_contains($root, '..')
            || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $root) !== 1) {
            throw new \DomainException('Workload roots must be safe relative repository directories.');
        }

        return $root;
    }

    /**
     * @return list<string>
     */
    private function discoverPackageRoots(SSHService $ssh, string $hostAppPath): array
    {
        $host = rtrim($hostAppPath, '/');
        $output = trim($ssh->exec(
            'find '.escapeshellarg($host)
            .' -maxdepth 4 \\( -name node_modules -o -name .git -o -name dist -o -name .next'
            .' -o -name ios -o -name android -o -name vendor \\) -prune -o -type f -name package.json -print'
            .' 2>/dev/null | head -n 40',
            20,
        ));
        if ($output === '') {
            return [];
        }

        $roots = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            $line = str_replace('\\', '/', trim($line));
            if ($line === '' || ! str_ends_with($line, '/package.json')) {
                continue;
            }
            $relative = ltrim(substr($line, strlen($host)), '/');
            $relative = preg_replace('#/package\.json$#', '', $relative) ?? $relative;
            if ($relative === '' || $relative === '.') {
                continue;
            }
            try {
                $roots[] = $this->sanitizeRelativeRoot($relative);
            } catch (\DomainException) {
                continue;
            }
        }

        return $this->uniqueRoots($roots);
    }

    /**
     * @return list<string>
     */
    private function discoverBackendRoots(SSHService $ssh, string $hostAppPath): array
    {
        $host = rtrim($hostAppPath, '/');
        $output = trim($ssh->exec(
            'find '.escapeshellarg($host)
            .' -maxdepth 4 \\( -name node_modules -o -name .git -o -name dist -o -name .next'
            .' -o -name vendor -o -name .venv -o -name __pycache__ \\) -prune -o -type f'
            .' \\( -name manage.py -o -name pyproject.toml -o -name requirements.txt'
            .' -o -name Gemfile -o -name config.ru -o -name go.mod -o -name main.go \\) -print'
            .' 2>/dev/null | head -n 60',
            20,
        ));
        if ($output === '') {
            return [];
        }

        $roots = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            $line = str_replace('\\', '/', trim($line));
            if ($line === '' || ! str_starts_with($line, $host.'/')) {
                continue;
            }
            $relative = ltrim(substr(dirname($line), strlen($host)), '/');
            try {
                $roots[] = $this->sanitizeRelativeRoot($relative === '' ? '.' : $relative);
            } catch (\DomainException) {
                continue;
            }
        }

        return $this->uniqueRoots($roots);
    }

    /**
     * When the customer asked for a browser app and the repo only has one
     * Expo/RN package beside the API, host that package as expo-web on the
     * same compose project (backend + frontend + edge).
     *
     * @param  list<string>  $skippedMobile
     * @return array{root: string, type: string}|null
     */
    private function resolveExpoWebFallback(
        SSHService $ssh,
        string $hostAppPath,
        array $skippedMobile,
        string $backendRoot,
    ): ?array {
        $candidates = [];
        foreach ($skippedMobile as $root) {
            if ($root === $backendRoot) {
                continue;
            }
            $package = $this->packageAt($ssh, $hostAppPath, $root);
            if ($package === null) {
                continue;
            }
            $kind = $this->explicitBrowserKind($package);
            if ($kind !== 'expo-web') {
                continue;
            }
            $candidates[$root] = ['root' => $root, 'type' => $kind];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return array_values($candidates)[0];
    }

    /**
     * @param  list<string>  $discovered
     * @return list<string>
     */
    private function mobileRoots(SSHService $ssh, string $hostAppPath, array $discovered): array
    {
        $roots = [];
        foreach ($this->uniqueRoots(['apps/mobile', 'mobile', 'apps/app', ...$discovered]) as $root) {
            $package = $this->packageAt($ssh, $hostAppPath, $root);
            if ($package !== null && $this->isNativeMobileOnly($package)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function uniqueRoots(array $roots): array
    {
        $seen = [];
        foreach ($roots as $root) {
            if (! is_string($root) || $root === '') {
                continue;
            }
            $seen[$root] = $root;
        }

        return array_values($seen);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function packageAt(SSHService $ssh, string $hostAppPath, string $root): ?array
    {
        $json = trim($ssh->exec(
            'head -c 131072 '.escapeshellarg(rtrim($hostAppPath, '/').'/'.$root.'/package.json').' 2>/dev/null || true',
            15,
        ));
        if ($json === '') {
            return null;
        }
        $package = json_decode($json, true);

        return is_array($package) ? $package : null;
    }

    private function isBackendAt(
        SSHService $ssh,
        string $hostAppPath,
        string $root,
        string $slug,
        string $framework,
    ): bool {
        if ($slug === 'nodejs') {
            $package = $this->packageAt($ssh, $hostAppPath, $root);

            return $package !== null && $this->isBackend($package, $framework);
        }

        $path = $root === '.' ? rtrim($hostAppPath, '/') : rtrim($hostAppPath, '/').'/'.$root;
        $file = fn (string $name): string => '[ -f '.escapeshellarg($path.'/'.$name).' ]';
        $probe = match ($slug) {
            'python' => match ($framework) {
                'django' => $file('manage.py'),
                'fastapi' => '('.$file('main.py').' || '.$file('app.py').') && grep -Eiq '
                    .escapeshellarg('fastapi|uvicorn').' '
                    .escapeshellarg($path.'/requirements.txt').' '.escapeshellarg($path.'/pyproject.toml').' 2>/dev/null',
                'flask' => '('.$file('app.py').' || '.$file('wsgi.py').') && grep -Eiq '
                    .escapeshellarg('flask|gunicorn').' '
                    .escapeshellarg($path.'/requirements.txt').' '.escapeshellarg($path.'/pyproject.toml').' 2>/dev/null',
                default => implode(' || ', array_map($file, [
                    'manage.py', 'requirements.txt', 'pyproject.toml', 'main.py', 'app.py', 'wsgi.py',
                ])),
            },
            'ruby' => $framework === 'rails'
                ? $file('bin/rails')
                : implode(' || ', array_map($file, ['Gemfile', 'bin/rails', 'config.ru'])),
            'go' => implode(' || ', array_map($file, ['go.mod', 'main.go', 'cmd/server/main.go'])),
            default => '',
        };

        return $probe !== ''
            && trim($ssh->exec('{ '.$probe.'; } && echo yes || echo no', 10)) === 'yes';
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function isBackend(array $package, string $framework): bool
    {
        $dependencies = $this->dependencies($package);
        $frameworkMatches = match ($framework) {
            'express' => isset($dependencies['express']),
            'nest' => isset($dependencies['@nestjs/core']),
            default => true,
        };
        $start = trim((string) ($package['scripts']['start'] ?? ''));
        $dev = trim((string) ($package['scripts']['dev'] ?? ''));

        return $frameworkMatches && ($start !== '' || $dev !== '') && ! $this->isNativeMobileOnly($package);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function browserFrontendKind(array $package): ?string
    {
        $dependencies = $this->dependencies($package);
        if (isset($dependencies['next'])) {
            return 'nextjs';
        }
        if (isset($dependencies['vite'])) {
            return 'vite-spa';
        }
        if (isset($dependencies['react-native-web']) || $this->hasExpoWebScripts($package)) {
            return 'expo-web';
        }

        return null;
    }

    /**
     * Operator-pinned roots may be Expo/RN that still ship a web export.
     *
     * @param  array<string, mixed>  $package
     */
    private function explicitBrowserKind(array $package): ?string
    {
        $kind = $this->browserFrontendKind($package);
        if ($kind !== null) {
            return $kind;
        }

        $dependencies = $this->dependencies($package);

        return (isset($dependencies['expo']) || isset($dependencies['react-native']))
            ? 'expo-web'
            : null;
    }

    /**
     * Metro/native-only clients. Vite, Next, and Expo web exports are hostable.
     *
     * @param  array<string, mixed>  $package
     */
    private function isNativeMobileOnly(array $package): bool
    {
        $dependencies = $this->dependencies($package);
        if (isset($dependencies['next'])
            || isset($dependencies['vite'])
            || isset($dependencies['react-native-web'])) {
            return false;
        }
        if ($this->hasExpoWebScripts($package)) {
            return false;
        }

        return isset($dependencies['expo']) || isset($dependencies['react-native']);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function hasExpoWebScripts(array $package): bool
    {
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        $build = strtolower((string) ($scripts['build'] ?? ''));
        $start = strtolower((string) ($scripts['start'] ?? ''));
        $web = strtolower((string) ($scripts['web'] ?? ''));

        return str_contains($build, 'expo export')
            || str_contains($start, '--web')
            || $web !== '';
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function isMobileBundle(array $package): bool
    {
        return $this->isNativeMobileOnly($package);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function dependencies(array $package): array
    {
        return array_merge(
            is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
            is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
        );
    }
}
