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
    private const BACKEND_CANDIDATES = ['apps/api', 'api', 'backend', 'server', 'apps/server', 'packages/api'];

    /**
     * Browser-app directories only. apps/mobile is never a Vite/Next host just
     * because it sits next to the API — scanning it first used to abort deploys.
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
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs') {
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
        $backendCandidates = $this->uniqueRoots([...self::BACKEND_CANDIDATES, ...$discovered]);
        $frontendCandidates = $this->uniqueRoots([...self::FRONTEND_CANDIDATES, ...$discovered]);

        if ($frontend === 'none') {
            $backendRoot = $this->resolveRoot(
                $ssh,
                $hostAppPath,
                'backend',
                $backendOverride,
                $backendCandidates,
                fn (array $package): bool => $this->isBackend($package, $framework),
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
            throw new \DomainException("The selected frontend '{$frontend}' is not supported by the split Node runtime.");
        }

        $effectiveFrontendOverride = $this->forgetMobileFrontendOverride(
            $ssh,
            $hostAppPath,
            $frontendOverride,
            $skippedMobile,
        );

        $backendRoot = $this->resolveRoot(
            $ssh,
            $hostAppPath,
            'backend',
            $backendOverride,
            $backendCandidates,
            fn (array $package): bool => $this->isBackend($package, $framework),
            required: true,
        );
        $browser = $this->resolveBrowserFrontend(
            $ssh,
            $hostAppPath,
            $frontend,
            $effectiveFrontendOverride,
            $frontendCandidates,
        );

        if ($browser !== null) {
            if ($backendRoot === $browser['root']) {
                throw new \DomainException('Backend and frontend roots must be different directories.');
            }

            return $this->splitTopology(
                $ssh,
                $hostAppPath,
                $backendRoot,
                $browser,
                ($backendOverride || $effectiveFrontendOverride) ? 'manual' : 'auto',
                $framework,
                $skippedMobile,
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
    ): array {
        $backendPackage = $this->packageAt($ssh, $hostAppPath, $backendRoot);
        $frontendPackage = $this->packageAt($ssh, $hostAppPath, $browser['root']);
        $versionService = app(ContainerNodeVersionService::class);
        $backendRuntime = app(ContainerApplicationRuntimeService::class)->detectNodeRuntimeAt(
            $ssh,
            $hostAppPath,
            $backendRoot,
            self::BACKEND_PORT,
            includeBootstrap: false,
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
        if ($mobileRoots !== []) {
            $notes[] = 'Skipped Expo/React Native at '.implode(', ', $mobileRoots)
                .'. The API at '.$backendRoot.' is what this host runs; build the mobile app with a mobile build service.';
        } elseif ($requestedFrontend !== 'none') {
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
     * @param  (callable(array<string, mixed>): bool)  $accepts
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
            $package = $this->packageAt($ssh, $hostAppPath, $root);
            if ($package === null || ! $accepts($package)) {
                throw new \DomainException("The selected {$role} root '{$root}' is not a valid {$role} application.");
            }

            return $root;
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            $package = $this->packageAt($ssh, $hostAppPath, $candidate);
            if ($package !== null && $accepts($package)) {
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
            if (! $this->isMobileBundle($package)) {
                $kind = $this->browserFrontendKind($package);
                if ($kind === null) {
                    throw new \DomainException("The selected frontend root '{$root}' is not a valid frontend application.");
                }

                return ['root' => $root, 'type' => $kind];
            }
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
     * A persisted Expo/RN frontend pin must not abort deploy. Drop it and keep
     * scanning for a real browser app (or fall back to API-only).
     *
     * @param  list<string>  $skippedMobile
     */
    private function forgetMobileFrontendOverride(
        SSHService $ssh,
        string $hostAppPath,
        ?string $frontendOverride,
        array &$skippedMobile,
    ): ?string {
        if ($frontendOverride === null || trim($frontendOverride) === '') {
            return null;
        }

        $root = $this->sanitizeRelativeRoot($frontendOverride);
        $package = $this->packageAt($ssh, $hostAppPath, $root);
        if ($package !== null && $this->isMobileBundle($package)) {
            $skippedMobile = $this->uniqueRoots([...$skippedMobile, $root]);

            return null;
        }

        return $frontendOverride;
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
            if ($package !== null && $this->isMobileBundle($package)) {
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

        return $frameworkMatches && ($start !== '' || $dev !== '') && ! $this->isMobileBundle($package);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function browserFrontendKind(array $package): ?string
    {
        if ($this->isMobileBundle($package)) {
            return null;
        }

        $dependencies = $this->dependencies($package);
        if (isset($dependencies['next'])) {
            return 'nextjs';
        }
        if (isset($dependencies['vite'])) {
            return 'vite-spa';
        }

        return null;
    }

    /**
     * Expo or React Native is a mobile client even when Vite is listed for
     * Expo web. Next.js in the same package is treated as a hostable web app.
     *
     * @param  array<string, mixed>  $package
     */
    private function isMobileBundle(array $package): bool
    {
        $dependencies = $this->dependencies($package);
        if (isset($dependencies['next'])) {
            return false;
        }

        return isset($dependencies['expo']) || isset($dependencies['react-native']);
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
