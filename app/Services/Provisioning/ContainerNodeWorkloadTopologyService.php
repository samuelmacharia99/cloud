<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerNodeWorkloadTopologyService
{
    public const SCHEMA = 1;

    public const BACKEND_PORT = 8000;

    public const FRONTEND_PORT = 3000;

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
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $frontend = strtolower((string) ($meta['frontend'] ?? 'none'));
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs' || $frontend === 'none') {
            return [
                'schema' => self::SCHEMA,
                'topology' => 'single',
                'selection_source' => 'stack',
            ];
        }

        if (! in_array($frontend, ['nextjs', 'vite-spa'], true)) {
            throw new \DomainException("The selected frontend '{$frontend}' is not supported by the split Node runtime.");
        }

        $backendRoot = $this->resolveRoot(
            $ssh,
            $hostAppPath,
            'backend',
            $backendOverride,
            ['apps/api', 'api', 'backend', 'server', 'apps/server'],
            fn (array $package): bool => $this->isBackend($package, (string) ($meta['framework'] ?? 'other')),
        );
        $frontendRoot = $this->resolveRoot(
            $ssh,
            $hostAppPath,
            'frontend',
            $frontendOverride,
            ['apps/mobile', 'apps/web', 'frontend', 'web', 'client', 'apps/app'],
            fn (array $package): bool => $this->isWebFrontend($package, $frontend),
            fn (array $rejected) => $this->explainMissingWebFrontend($rejected),
        );

        if ($backendRoot === $frontendRoot) {
            throw new \DomainException('Backend and frontend roots must be different directories.');
        }
        $backendPackage = $this->packageAt($ssh, $hostAppPath, $backendRoot);
        $frontendPackage = $this->packageAt($ssh, $hostAppPath, $frontendRoot);
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
            $frontendRoot,
            self::FRONTEND_PORT,
            includeBootstrap: false,
        );

        return [
            'schema' => self::SCHEMA,
            'topology' => 'split_web_api',
            'selection_source' => ($backendOverride || $frontendOverride) ? 'manual' : 'auto',
            'framework' => (string) ($meta['framework'] ?? 'other'),
            'frontend_type' => $frontend,
            'backend' => [
                'root' => $backendRoot,
                'port' => self::BACKEND_PORT,
                'runtime_source' => $backendRuntime->source,
                'runtime_label' => $backendRuntime->label,
                'working_directory' => $backendRuntime->containerWorkdir,
                'start_command' => $backendRuntime->command,
                'node_engine' => $versionService->constraintFromPackageJson(
                    json_encode($backendPackage, JSON_THROW_ON_ERROR),
                ),
            ],
            'frontend' => [
                'root' => $frontendRoot,
                'port' => self::FRONTEND_PORT,
                'runtime_source' => $frontendRuntime->source,
                'runtime_label' => $frontendRuntime->label,
                'working_directory' => $frontendRuntime->containerWorkdir,
                'start_command' => $frontendRuntime->command,
                'node_engine' => $versionService->constraintFromPackageJson(
                    json_encode($frontendPackage, JSON_THROW_ON_ERROR),
                ),
            ],
            'resolved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function persist(Service $service, array $topology): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['node_workloads'] = $topology;
        $service->update(['service_meta' => $meta]);
    }

    /**
     * @param  list<string>  $candidates
     * @param  (callable(array<string, array<string, mixed>>): void)|null  $explainNoMatch
     *                                                                                      Given the directories that hold an application but were not accepted,
     *                                                                                      may throw a more precise reason than "nothing matched".
     */
    private function resolveRoot(
        SSHService $ssh,
        string $hostAppPath,
        string $role,
        ?string $override,
        array $candidates,
        callable $accepts,
        ?callable $explainNoMatch = null,
    ): string {
        if ($override !== null && trim($override) !== '') {
            $root = $this->sanitizeRelativeRoot($override);
            $package = $this->packageAt($ssh, $hostAppPath, $root);
            if ($package === null || ! $accepts($package)) {
                if ($explainNoMatch !== null && $package !== null) {
                    $explainNoMatch([$root => $package]);
                }

                throw new \DomainException("The selected {$role} root '{$root}' is not a valid {$role} application.");
            }

            return $root;
        }

        $matches = [];
        $rejected = [];
        foreach ($candidates as $candidate) {
            $package = $this->packageAt($ssh, $hostAppPath, $candidate);
            if ($package === null) {
                continue;
            }
            if ($accepts($package)) {
                $matches[] = $candidate;
            } else {
                $rejected[$candidate] = $package;
            }
        }
        if ($matches === []) {
            if ($explainNoMatch !== null) {
                $explainNoMatch($rejected);
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

        return $frameworkMatches && ($start !== '' || $dev !== '');
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function isWebFrontend(array $package, string $frontend): bool
    {
        $dependencies = $this->dependencies($package);

        return $frontend === 'nextjs'
            ? isset($dependencies['next'])
            : isset($dependencies['vite']);
    }

    /**
     * A repository may ship a mobile app beside its web app, and apps/mobile is
     * scanned first. Rejecting that directory must not end the search, so the
     * mobile bundle is only reported once no browser frontend was found at all.
     *
     * @param  array<string, array<string, mixed>>  $rejected
     */
    private function explainMissingWebFrontend(array $rejected): void
    {
        foreach ($rejected as $root => $package) {
            if ($this->isMobileBundle($package)) {
                throw new \DomainException(sprintf(
                    "The frontend at '%s' is Expo/React Native, not a browser application. Deploy its API here"
                    .' and build the mobile app through a mobile build service, or point Advanced roots at the'
                    .' browser application if the repository also ships one.',
                    $root,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function isMobileBundle(array $package): bool
    {
        $dependencies = $this->dependencies($package);

        return (isset($dependencies['expo']) || isset($dependencies['react-native']))
            && ! isset($dependencies['next'])
            && ! isset($dependencies['vite']);
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
