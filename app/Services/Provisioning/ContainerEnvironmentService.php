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
        'ASSET_URL',
        'FRONTEND_URL',
        'TALKSASA_CLOUD_URL',
        'DATABASE_URL',
        'INTERNAL_API_URL',
        'BACKEND_URL',
        'NEXT_PUBLIC_APP_URL',
        'NEXT_PUBLIC_API_URL',
    ];

    /**
     * @return array{
     *     variables: list<array{key: string, value: string, sensitive: bool, platform_managed: bool, required_by_app: bool, unset: bool}>,
     *     can_save: bool,
     *     can_apply: bool,
     *     applies_dotenv: bool,
     *     template_slug: ?string,
     *     deployment_status: ?string,
     *     required_by_app: list<string>,
     *     suggested_by_app: list<string>
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
                'required_by_app' => false,
                'rejected_by_app' => false,
                'unset' => false,
            ];
        }

        // Keys the application asked for and nothing has set. Required ones are
        // holding the app from starting; declared ones are optional settings its
        // own example file mentions. Neither is written into the container until
        // the customer supplies a value.
        $requirements = app(ApplicationEnvironmentRequirements::class);
        $required = $requirements->outstandingRequired($service, $deployment);
        $suggested = $requirements->outstandingDeclared($service, $deployment);

        // Names the application rejected the value of. They are set, so they
        // are already rows above; this marks them rather than adding them.
        $rejected = $requirements->invalid($service);
        foreach ($variables as $index => $variable) {
            if (in_array($variable['key'], $rejected, true)) {
                $variables[$index]['rejected_by_app'] = true;
            }
        }

        foreach ([...$required, ...$suggested] as $key) {
            $variables[] = [
                'key' => $key,
                'value' => '',
                'sensitive' => $this->isSensitiveKey($key),
                'platform_managed' => false,
                'required_by_app' => in_array($key, $required, true),
                'rejected_by_app' => false,
                'unset' => true,
            ];
        }

        $status = $deployment?->status;
        $canSave = $deployment !== null && ! in_array($status, ['terminated'], true);
        // Apply restarts the stack; allow while deploying too (Save was previously disabled then).
        $canApply = $canSave && in_array($status, ['running', 'stopped', 'failed', 'deploying', 'pending', 'provisioning'], true);

        return [
            'variables' => $variables,
            'can_save' => $canSave,
            'can_apply' => $canApply,
            'applies_dotenv' => in_array($slug, ['laravel', 'php'], true),
            'template_slug' => $slug,
            'deployment_status' => $status,
            'required_by_app' => $required,
            'suggested_by_app' => $suggested,
            'rejected_by_app' => $rejected,
        ];
    }

    /**
     * @param  list<array{key?: string, value?: string|null}>|array<string, string>  $incoming
     * @return array{updated: int, skipped: list<string>, applied: bool, message: string}
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
        $skipped = [];

        foreach ($normalized as $key => $value) {
            if ($this->isPlatformManagedKey($key) && array_key_exists($key, $current) && (string) $current[$key] !== $value) {
                // Allow updates to platform keys but keep them — customer may fix APP_URL etc.
                // DB_* changes require compose recreate + credential awareness.
            }

            // A blank for a key that has never had a value is the panel's own
            // suggestion row being saved untouched, not a decision. Writing it
            // is worse than ignoring it: an absent setting falls back to the
            // application's default, while one present and empty is handed to
            // the parser, which refuses it and crash-loops the container. That
            // is how a service ended up rejecting its own ENABLE_SMS.
            //
            // Clearing a key that does exist is still a real instruction, so
            // only the first case is dropped.
            if ($value === '' && ! array_key_exists($key, $current)) {
                $skipped[] = $key;

                continue;
            }

            $current[$key] = $value;
        }

        $deployment->update(['env_values' => $current]);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['env_values'] = $current;
        $service->update(['service_meta' => $meta]);

        $message = 'Environment variables saved.';
        if ($skipped !== []) {
            $message .= ' '.implode(', ', $skipped).' had no value, so '
                .(count($skipped) === 1 ? 'it was' : 'they were')
                .' left unset rather than saved empty.';
        }
        $applied = false;

        if ($restart) {
            try {
                app(ContainerDeploymentService::class)->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
                $message = 'Environment variables saved and applied to the running stack.';
                $applied = true;
            } catch (\Throwable $e) {
                Log::error('Environment variables saved but stack apply failed', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException(
                    'Environment variables were saved, but applying them to the stack failed: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return [
            'updated' => count($normalized) - count($skipped),
            'skipped' => $skipped,
            'applied' => $applied,
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
        $slug = $service->effectiveContainerTemplate()?->slug
            ?? $service->product?->containerTemplate?->slug;
        if (! in_array($slug, ['laravel', 'php', 'nodejs'], true)) {
            return;
        }

        $hostAppPath = '/opt/talksasa/containers/'.$deployment->container_name.'/app';
        $relative = trim((string) (is_array($service->service_meta) ? ($service->service_meta['laravel_project_root'] ?? '') : ''), '/');
        $paths = [$hostAppPath.'/.env', $hostAppPath.'/backend/.env'];
        if (in_array($slug, ['nodejs'], true)) {
            $paths[] = $hostAppPath.'/.env.production';
            $paths[] = $hostAppPath.'/.env.local';
        }
        if ($relative !== '') {
            array_unshift($paths, $hostAppPath.'/'.$relative.'/.env');
        }

        foreach (array_values(array_unique($paths)) as $envPath) {
            try {
                $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no'));
                if ($exists !== 'yes') {
                    continue;
                }

                $content = $ssh->exec('cat '.escapeshellarg($envPath));
                $updated = $this->mergeEnvFileContent($content, $envValues);
                $updated = $this->removeEnvFileKeys(
                    $updated,
                    app(ContainerDeploymentService::class)->mysqlUnixSocketEnvKeys()
                );
                $ssh->upload($updated, $envPath);
            } catch (\Throwable $e) {
                Log::warning('Failed to sync container .env file after environment update', [
                    'service_id' => $service->id,
                    'env_path' => $envPath,
                    'error' => $e->getMessage(),
                ]);
            }
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

    /**
     * @param  list<string>  $keys
     */
    public function removeEnvFileKeys(string $content, array $keys): string
    {
        if ($keys === []) {
            return $content;
        }

        $remove = array_fill_keys($keys, true);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (str_contains($line, '=') && ! str_starts_with(ltrim($line), '#')) {
                [$key] = explode('=', $line, 2);
                if (isset($remove[trim($key)])) {
                    continue;
                }
            }
            $result[] = $line;
        }

        return implode("\n", $result)."\n";
    }

    private function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#$"\'\\\\]/', $value)) {
            if (str_contains($value, '$')) {
                return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
