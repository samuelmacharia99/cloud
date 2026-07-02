<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerPhpExtensionsService
{
    public function supportsTemplate(?string $slug): bool
    {
        return in_array($slug, ['laravel', 'php'], true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function optionalExtensionCatalog(): array
    {
        return config('php_extensions.extensions', []);
    }

    /**
     * @return list<string>
     */
    public function builtinExtensionNames(): array
    {
        return array_values(config('php_extensions.builtin', []));
    }

    /**
     * @return list<string>
     */
    public function enabledExtensionKeys(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $enabled = $meta['php_extensions'] ?? [];

        if (! is_array($enabled)) {
            return [];
        }

        $catalogKeys = array_keys($this->optionalExtensionCatalog());

        return array_values(array_unique(array_filter(
            $enabled,
            fn ($key) => is_string($key) && in_array($key, $catalogKeys, true)
        )));
    }

    /**
     * @return list<string>
     */
    public function listInstalledModuleNames(SSHService $ssh, ContainerDeployment $deployment): array
    {
        app(ContainerDeploymentService::class)->waitForContainerRunning($ssh, $deployment->container_name);

        $output = trim($ssh->exec(
            'docker exec -u www-data -w / '.escapeshellarg($deployment->container_name)
            .' sh -lc '.escapeshellarg('php -m'),
            30
        ));

        if ($output === '') {
            return [];
        }

        $modules = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '[')) {
                continue;
            }

            $modules[] = $line;
        }

        return array_values(array_unique($modules));
    }

    /**
     * @return array{
     *     available: bool,
     *     builtin: list<array{key: string, label: string, installed: bool}>,
     *     optional: list<array{key: string, label: string, description: string, enabled: bool, installed: bool}>
     * }
     */
    public function buildPanelState(Service $service, ?ContainerDeployment $deployment): array
    {
        $installed = [];
        $containerRunning = $deployment && $deployment->status === 'running' && $deployment->node;

        if ($containerRunning) {
            try {
                $ssh = SSHService::forNode($deployment->node);
                try {
                    $installed = $this->listInstalledModuleNames($ssh, $deployment);
                } finally {
                    $ssh->disconnect();
                }
            } catch (\Throwable) {
                $installed = [];
            }
        }

        $enabled = $this->enabledExtensionKeys($service);
        $builtin = [];
        foreach ($this->builtinExtensionNames() as $name) {
            $builtin[] = [
                'key' => $name,
                'label' => strtoupper($name),
                'installed' => in_array(strtolower($name), $installed, true),
            ];
        }

        $optional = [];
        foreach ($this->optionalExtensionCatalog() as $key => $definition) {
            $moduleName = strtolower((string) ($definition['install'] ?? $key));
            $optional[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? strtoupper($key)),
                'description' => (string) ($definition['description'] ?? ''),
                'enabled' => in_array($key, $enabled, true),
                'installed' => in_array($moduleName, $installed, true),
            ];
        }

        return [
            'available' => $this->supportsTemplate($service->product?->containerTemplate?->slug),
            'builtin' => $builtin,
            'optional' => $optional,
            'container_running' => (bool) $containerRunning,
        ];
    }

    /**
     * @param  list<string>|null  $requestedKeys
     */
    public function sync(Service $service, ContainerDeployment $deployment, SSHService $ssh, ?array $requestedKeys = null): string
    {
        if ($deployment->status !== 'running') {
            throw new \DomainException('Container must be running to manage PHP extensions.');
        }

        $catalog = $this->optionalExtensionCatalog();
        $keys = $requestedKeys ?? $this->enabledExtensionKeys($service);
        $keys = array_values(array_unique(array_filter(
            $keys,
            fn ($key) => is_string($key) && array_key_exists($key, $catalog)
        )));

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['php_extensions'] = $keys;
        $meta['php_extensions_synced_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);

        $installed = [];
        foreach ($keys as $key) {
            if ($this->ensureExtensionInstalled($ssh, $deployment, $key)) {
                $installed[] = $catalog[$key]['label'] ?? $key;
            }
        }

        if ($installed === []) {
            return 'PHP extension preferences saved.';
        }

        return 'PHP extensions enabled: '.implode(', ', $installed).'. Restart the container if your app still reports a missing extension.';
    }

    public function syncEnabledExtensions(Service $service, ContainerDeployment $deployment, SSHService $ssh): void
    {
        if ($deployment->status !== 'running') {
            return;
        }

        foreach ($this->enabledExtensionKeys($service) as $key) {
            $this->ensureExtensionInstalled($ssh, $deployment, $key);
        }
    }

    public function ensureExtensionInstalled(SSHService $ssh, ContainerDeployment $deployment, string $extensionKey): bool
    {
        $catalog = $this->optionalExtensionCatalog();
        if (! array_key_exists($extensionKey, $catalog)) {
            throw new \InvalidArgumentException("Unknown PHP extension [{$extensionKey}].");
        }

        $definition = $catalog[$extensionKey];
        $moduleName = strtolower((string) ($definition['install'] ?? $extensionKey));

        if ($this->moduleIsInstalled($ssh, $deployment, $moduleName)) {
            return false;
        }

        $timeout = (int) config('php_extensions.install_timeout_seconds', 300);
        $containerName = escapeshellarg($deployment->container_name);
        $script = $this->buildInstallScript($extensionKey);

        app(ContainerDeploymentService::class)->waitForContainerRunning($ssh, $deployment->container_name);

        $ssh->exec(
            'docker exec -u 0 -w / '.$containerName.' sh -lc '.escapeshellarg($script),
            $timeout
        );

        if (! $this->moduleIsInstalled($ssh, $deployment, $moduleName)) {
            throw new \RuntimeException("PHP extension [{$extensionKey}] could not be enabled.");
        }

        return true;
    }

    public function buildInstallScript(string $extensionKey): string
    {
        $definition = $this->optionalExtensionCatalog()[$extensionKey] ?? null;
        if (! is_array($definition)) {
            throw new \InvalidArgumentException("Unknown PHP extension [{$extensionKey}].");
        }

        $parts = ['set -e', 'export DEBIAN_FRONTEND=noninteractive'];

        $aptPackages = array_values(array_filter(
            is_array($definition['apt'] ?? null) ? $definition['apt'] : [],
            fn ($package) => is_string($package) && $package !== ''
        ));

        if ($aptPackages !== []) {
            $packages = implode(' ', array_map('escapeshellarg', $aptPackages));
            $parts[] = 'apt-get update -qq';
            $parts[] = "apt-get install -y --no-install-recommends {$packages}";
        }

        if (! empty($definition['configure']) && is_string($definition['configure'])) {
            $parts[] = $definition['configure'];
        }

        if (! empty($definition['pecl']) && is_string($definition['pecl'])) {
            $peclPackage = escapeshellarg($definition['pecl']);
            $moduleName = escapeshellarg((string) ($definition['install'] ?? $extensionKey));
            $parts[] = "pecl install -o -f {$peclPackage}";
            $parts[] = "docker-php-ext-enable {$moduleName}";
        } else {
            $installName = escapeshellarg((string) ($definition['install'] ?? $extensionKey));
            $parts[] = "docker-php-ext-install -j\"$(nproc)\" {$installName}";
        }

        return implode(' && ', $parts);
    }

    private function moduleIsInstalled(SSHService $ssh, ContainerDeployment $deployment, string $moduleName): bool
    {
        try {
            $ssh->exec(
                'docker exec -u www-data -w / '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg('php -m | grep -qx '.escapeshellarg($moduleName)),
                20
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
