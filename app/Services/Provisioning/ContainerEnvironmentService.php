<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ContainerEnvironmentService
{
    /**
     * Keys owned by the platform (DB sidecar / URLs). Editable only with apply + sync.
     *
     * @var list<string>
     */
    public const PLATFORM_MANAGED_KEYS = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'MYSQL_DATABASE',
        'MYSQL_USER',
        'MYSQL_PASSWORD',
        'MYSQL_ROOT_PASSWORD',
        'POSTGRES_DB',
        'POSTGRES_USER',
        'POSTGRES_PASSWORD',
        'MONGO_INITDB_ROOT_USERNAME',
        'MONGO_INITDB_ROOT_PASSWORD',
        'MONGO_INITDB_DATABASE',
        'APP_URL',
        'TALKSASA_CLOUD_URL',
        'DATABASE_URL',
    ];

    /**
     * @return array{
     *     variables: list<array{key: string, value: string, sensitive: bool, platform_managed: bool}>,
     *     can_apply: bool,
     *     applies_dotenv: bool,
     *     template_slug: ?string
     * }
     */
    public function buildPanelState(Service $service, ?ContainerDeployment $deployment): array
    {
        $slug = $service->product?->containerTemplate?->slug;
        $env = is_array($deployment?->env_values) ? $deployment->env_values : [];

        ksort($env);

        $variables = [];
        foreach ($env as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }

            $variables[] = [
                'key' => $key,
                'value' => (string) $value,
                'sensitive' => $this->isSensitiveKey($key),
                'platform_managed' => $this->isPlatformManagedKey($key),
            ];
        }

        return [
            'variables' => $variables,
            'can_apply' => $deployment !== null && in_array($deployment->status, ['running', 'stopped', 'failed'], true),
            'applies_dotenv' => in_array($slug, ['laravel', 'php'], true),
            'template_slug' => $slug,
        ];
    }

    /**
     * @param  list<array{key?: string, value?: string|null}>|array<string, string>  $incoming
     * @return array{updated: int, message: string}
     */
    public function updateVariables(Service $service, array $incoming, bool $restart = true): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            throw new \DomainException('Container is not deployed yet.');
        }

        $normalized = $this->normalizeIncoming($incoming);
        $current = is_array($deployment->env_values) ? $deployment->env_values : [];

        foreach ($normalized as $key => $value) {
            if ($this->isPlatformManagedKey($key) && array_key_exists($key, $current) && (string) $current[$key] !== $value) {
                // Allow updates to platform keys but keep them — customer may fix APP_URL etc.
                // DB_* changes require compose recreate + credential awareness.
            }
            $current[$key] = $value;
        }

        $deployment->update(['env_values' => $current]);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['env_values'] = $current;
        $service->update(['service_meta' => $meta]);

        $message = 'Environment variables saved.';

        if ($restart) {
            app(ContainerDeploymentService::class)->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
            $message = 'Environment variables saved and applied to the running stack.';
        }

        return [
            'updated' => count($normalized),
            'message' => $message,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{deleted: int, message: string}
     */
    public function deleteVariables(Service $service, array $keys, bool $restart = true): array
    {
        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            throw new \DomainException('Container is not deployed yet.');
        }

        $current = is_array($deployment->env_values) ? $deployment->env_values : [];
        $deleted = 0;

        foreach ($keys as $key) {
            $key = strtoupper(trim((string) $key));
            if ($key === '' || ! array_key_exists($key, $current)) {
                continue;
            }

            if ($this->isPlatformManagedKey($key)) {
                throw ValidationException::withMessages([
                    'keys' => "Cannot delete platform-managed variable {$key}.",
                ]);
            }

            unset($current[$key]);
            $deleted++;
        }

        $deployment->update(['env_values' => $current]);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['env_values'] = $current;
        $service->update(['service_meta' => $meta]);

        $message = $deleted === 1
            ? 'Environment variable removed.'
            : "{$deleted} environment variables removed.";

        if ($restart && $deleted > 0) {
            app(ContainerDeploymentService::class)->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
            $message .= ' Stack restarted to apply changes.';
        }

        return [
            'deleted' => $deleted,
            'message' => $message,
        ];
    }

    public function isPlatformManagedKey(string $key): bool
    {
        return in_array(strtoupper($key), self::PLATFORM_MANAGED_KEYS, true);
    }

    public function isSensitiveKey(string $key): bool
    {
        $upper = strtoupper($key);

        return (bool) preg_match('/(PASSWORD|SECRET|TOKEN|KEY|PRIVATE|CREDENTIAL|AUTH)/', $upper);
    }

    /**
     * @param  list<array{key?: string, value?: string|null}>|array<string, string>  $incoming
     * @return array<string, string>
     */
    private function normalizeIncoming(array $incoming): array
    {
        $pairs = [];

        // Support [{key, value}, ...] or {KEY: value}
        $isList = array_is_list($incoming);

        if ($isList) {
            foreach ($incoming as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = strtoupper(trim((string) ($row['key'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $pairs[$key] = (string) ($row['value'] ?? '');
            }
        } else {
            foreach ($incoming as $key => $value) {
                $key = strtoupper(trim((string) $key));
                if ($key === '') {
                    continue;
                }
                $pairs[$key] = (string) $value;
            }
        }

        if ($pairs === []) {
            throw ValidationException::withMessages([
                'variables' => 'Provide at least one environment variable.',
            ]);
        }

        foreach ($pairs as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw ValidationException::withMessages([
                    'variables' => "Invalid variable name: {$key}. Use uppercase letters, numbers, and underscores.",
                ]);
            }

            if (strlen($key) > 100) {
                throw ValidationException::withMessages([
                    'variables' => "Variable name {$key} is too long.",
                ]);
            }

            if (strlen($value) > 4000) {
                throw ValidationException::withMessages([
                    'variables' => "Value for {$key} is too long (max 4000 characters).",
                ]);
            }
        }

        return $pairs;
    }

    /**
     * Upsert keys into a host-mounted .env file when present (Laravel/PHP).
     *
     * @param  array<string, string>  $envValues
     */
    public function syncDotEnvFile(SSHService $ssh, Service $service, ContainerDeployment $deployment, array $envValues): void
    {
        $slug = $service->product?->containerTemplate?->slug;
        if (! in_array($slug, ['laravel', 'php'], true)) {
            return;
        }

        $hostAppPath = '/opt/talksasa/containers/'.$deployment->container_name.'/app';
        $envPath = $hostAppPath.'/.env';

        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no'));
            if ($exists !== 'yes') {
                return;
            }

            $content = $ssh->exec('cat '.escapeshellarg($envPath));
            $updated = $this->mergeEnvFileContent($content, $envValues);
            $ssh->upload($updated, $envPath);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync container .env file after environment update', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public function mergeEnvFileContent(string $content, array $replacements): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $seen = [];
        $result = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '=') || str_starts_with(ltrim($line), '#')) {
                $result[] = $line;

                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '' || ! array_key_exists($key, $replacements)) {
                $result[] = $line;

                continue;
            }

            $result[] = $key.'='.$this->quoteEnvValue($replacements[$key]);
            $seen[$key] = true;
        }

        foreach ($replacements as $key => $value) {
            if (! isset($seen[$key])) {
                $result[] = $key.'='.$this->quoteEnvValue($value);
            }
        }

        return implode("\n", $result)."\n";
    }

    private function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
