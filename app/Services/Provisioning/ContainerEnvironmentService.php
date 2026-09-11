<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ContainerEnvironmentService
{
    /** The application named it on start-up and it still has no usable value. */
    public const STATE_NEEDS_VALUE = 'needs_value';

    /** It holds a value the application refused. */
    public const STATE_REJECTED = 'rejected';

    /** The repository's example file mentions it and nobody ever set it. */
    public const STATE_SUGGESTED = 'suggested';

    /** Database wiring and the like: editable, but the platform owns it. */
    public const STATE_PLATFORM = 'platform';

    /** An ordinary value the customer chose. */
    public const STATE_SET = 'set';

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

        $requirements = app(ApplicationEnvironmentRequirements::class);

        // Three questions with three different answers. Required means the
        // application named it on start-up and it still has no usable value.
        // Rejected means it has one the application refused. Suggested means
        // the repository's example file mentions it and nobody ever set it.
        $required = $requirements->outstandingRequired($service, $deployment);
        $rejected = $requirements->invalid($service);
        $suggested = $requirements->outstandingDeclared($service, $deployment);

        $variables = [];
        $seen = [];

        foreach ($env as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }

            $seen[$key] = true;
            $variables[] = $this->panelRow(
                $key,
                (string) $value,
                required: in_array($key, $required, true),
                rejected: in_array($key, $rejected, true),
                suggested: false,
            );
        }

        // Only the names with no row yet. A setting present and empty is both
        // stored and outstanding, and appending it here as well put it on the
        // screen twice: the same key, two identical empty boxes, on exactly the
        // settings a customer had come to the page to fix.
        foreach ([...$required, ...$suggested] as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $variables[] = $this->panelRow(
                $key,
                '',
                required: in_array($key, $required, true),
                rejected: false,
                suggested: ! in_array($key, $required, true),
            );
        }

        $variables = $this->attentionFirst($variables);

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
     * One row, and the single word the view switches on.
     *
     * Ranked rather than combined: a setting that is both rejected and missing
     * is missing, because supplying it is the one action that fixes both.
     *
     * @return array<string, mixed>
     */
    private function panelRow(
        string $key,
        string $value,
        bool $required,
        bool $rejected,
        bool $suggested,
    ): array {
        $platformManaged = $this->isPlatformManagedKey($key);

        $state = match (true) {
            $required => self::STATE_NEEDS_VALUE,
            $rejected => self::STATE_REJECTED,
            $suggested => self::STATE_SUGGESTED,
            $platformManaged => self::STATE_PLATFORM,
            default => self::STATE_SET,
        };

        return [
            'key' => $key,
            'value' => $value,
            'sensitive' => $this->isSensitiveKey($key),
            'platform_managed' => $platformManaged,
            'required_by_app' => $required,
            'rejected_by_app' => $rejected,
            'unset' => $suggested || ($required && trim($value) === ''),
            'suggested' => $suggested,
            'state' => $state,
        ];
    }

    /**
     * Put the rows holding the application down where they can be seen.
     *
     * usort is stable in PHP 8, so the alphabetical order the environment was
     * read in survives inside each group. Twenty platform keys no longer sit
     * between a customer and the four settings their site is stopped on.
     *
     * @param  list<array<string, mixed>>  $variables
     * @return list<array<string, mixed>>
     */
    private function attentionFirst(array $variables): array
    {
        $weight = [
            self::STATE_NEEDS_VALUE => 0,
            self::STATE_REJECTED => 1,
            self::STATE_SET => 2,
            self::STATE_PLATFORM => 3,
            self::STATE_SUGGESTED => 4,
        ];

        usort(
            $variables,
            fn (array $a, array $b): int => ($weight[$a['state']] ?? 2) <=> ($weight[$b['state']] ?? 2),
        );

        return $variables;
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
                $confirmed = app(ContainerDeploymentService::class)
                    ->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
                $message = $confirmed
                    ? 'Environment variables saved and applied to the running stack.'
                    : 'Environment variables saved and applied. The application had not finished starting yet, '
                        .'so watch the Logs tab or run Diagnose if the site does not come back shortly.';
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
     * @return array{deleted: int, dismissed: list<string>, message: string}
     */
    public function deleteVariables(Service $service, array $keys, bool $restart = true): array
    {
        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            throw new \DomainException('Container is not deployed yet.');
        }

        $current = is_array($deployment->env_values) ? $deployment->env_values : [];
        $requirements = app(ApplicationEnvironmentRequirements::class);
        $declared = $requirements->declared($service);
        $deleted = 0;
        $dismissed = [];

        foreach ($keys as $key) {
            $key = strtoupper(trim((string) $key));
            if ($key === '') {
                continue;
            }

            if (! array_key_exists($key, $current)) {
                // A name the repository suggests and nobody ever set. There is
                // nothing to delete, and reporting that removed nothing left
                // the row on screen after every reload, because the panel
                // rebuilds it from the example file. Remembering the refusal is
                // what the customer was actually asking for.
                if (in_array($key, $declared, true)) {
                    $dismissed[] = $key;
                }

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

        if ($dismissed !== []) {
            $requirements->rememberDismissed($service->fresh() ?? $service, $dismissed);
        }

        $message = match (true) {
            $deleted === 0 && $dismissed !== [] => count($dismissed) === 1
                ? 'Suggestion dismissed. It will not be offered again.'
                : count($dismissed).' suggestions dismissed. They will not be offered again.',
            $deleted === 1 => 'Environment variable removed.',
            default => "{$deleted} environment variables removed.",
        };

        if ($deleted > 0 && $dismissed !== []) {
            $message .= ' '.count($dismissed).' suggestion(s) dismissed.';
        }

        if ($restart && $deleted > 0) {
            $confirmed = app(ContainerDeploymentService::class)
                ->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
            $message .= $confirmed
                ? ' Stack restarted to apply changes.'
                : ' Stack restarted. The application had not finished starting yet, so watch the Logs tab.';
        }

        return [
            'deleted' => $deleted,
            'dismissed' => $dismissed,
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
