<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use Illuminate\Support\Str;

class ContainerTemplateEnvironmentService
{
    private ContainerAllowedHostnamesResolver $hostnames;

    private EmbeddedDatabaseSidecarReconciler $embeddedDatabases;

    public function __construct(
        ?ContainerAllowedHostnamesResolver $hostnames = null,
        ?EmbeddedDatabaseSidecarReconciler $embeddedDatabases = null,
    ) {
        $this->hostnames = $hostnames ?? new ContainerAllowedHostnamesResolver;
        $this->embeddedDatabases = $embeddedDatabases ?? new EmbeddedDatabaseSidecarReconciler;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public function prepare(object $template, array $env, Service $service, ?int $port = null): array
    {
        $env = $this->fillMissingRequiredVariables($template, $env, $service, $port);

        if (($template->slug ?? '') === 'wordpress') {
            $env = $this->prepareWordPressEnvironment($env);
        }

        if (($template->slug ?? '') === 'hermes') {
            $env = $this->prepareHermesEnvironment($env, $service);
        }

        if (($template->slug ?? '') === 'openclaw') {
            $env = $this->prepareOpenClawEnvironment($env);
        }

        if (($template->slug ?? '') === 'n8n') {
            $env = $this->prepareN8nEnvironment($env, $port);
        }

        if (($template->slug ?? '') === 'directus') {
            $env = $this->prepareDirectusEnvironment($env, $service);
        }

        if (($template->slug ?? '') === 'chatwoot') {
            $env = $this->prepareChatwootEnvironment($env, $service, $port);
        }

        if (($template->slug ?? '') === 'odoo') {
            $env = $this->prepareOdooEnvironment($env);
        }

        if (($template->slug ?? '') === 'erpnext') {
            $env = $this->prepareErpnextEnvironment($env);
        }

        if (($template->slug ?? '') === 'ospos') {
            $env = $this->prepareOsposEnvironment($env, $service);
        }

        if (($template->slug ?? '') === 'python') {
            $env = $this->preparePythonEnvironment($env);
        }

        if (in_array($template->slug ?? '', ['laravel', 'php'], true)) {
            // Customer Terminal + npm run as www-data; avoid root-owned /var/www/.npm.
            $env['HOME'] = $env['HOME'] ?? '/tmp';
            $env['NPM_CONFIG_CACHE'] = $env['NPM_CONFIG_CACHE'] ?? '/tmp/.npm';
            $env['npm_config_cache'] = $env['npm_config_cache'] ?? '/tmp/.npm';
            // Database cache needs cache_locks migrations many apps never ship.
            if (! isset($env['CACHE_STORE']) || trim((string) $env['CACHE_STORE']) === '') {
                $env['CACHE_STORE'] = 'file';
            }
            if (! isset($env['CACHE_DRIVER']) || trim((string) $env['CACHE_DRIVER']) === '') {
                $env['CACHE_DRIVER'] = 'file';
            }
        }

        return $env;
    }

    /**
     * Everything the customer supplied at checkout, added under Environment, or
     * that a previous deploy generated. A rebuild starts from this so variables
     * the stack template does not declare survive a redeploy; platform-owned
     * keys are re-derived afterwards and still win.
     *
     * @param  array<array-key, mixed>  $userValues
     * @return array<string, string>
     */
    public function normalizeCustomerValues(array $userValues): array
    {
        $values = [];

        foreach ($userValues as $key => $value) {
            $key = (string) $key;

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $values[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $values;
    }

    public function templateDefinesDatabaseSidecar(object $template): bool
    {
        $services = $template->compose_services ?? null;
        if (! is_array($services)) {
            return false;
        }

        $databaseServices = ['mysql', 'mariadb', 'postgresql', 'postgres', 'mongodb', 'mongo', 'db'];

        foreach (array_keys($services) as $serviceName) {
            if (in_array(strtolower((string) $serviceName), $databaseServices, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     */
    /**
     * Share of a plan's memory the database sidecar is reserved.
     */
    public static function databaseMemoryMb(int $planMemoryMb): int
    {
        return max(64, (int) floor($planMemoryMb * ContainerElasticResourceService::DATABASE_SHARE));
    }

    /**
     * MySQL command flags for a plan.
     *
     * The buffer pool takes most of the database's own reservation, which is
     * where InnoDB wants to live; the rest covers connections, the table cache
     * and the server itself. Without a plan the historical fixed values are
     * returned unchanged, so any caller that does not know the plan keeps
     * exactly today's behaviour.
     *
     * @return list<string>
     */
    public function mysqlTuningFlags(?int $planMemoryMb = null): array
    {
        return self::tuningFlagsFor($planMemoryMb);
    }

    /**
     * @return list<string>
     */
    public static function tuningFlagsFor(?int $planMemoryMb = null): array
    {
        $bufferPoolMb = 256;
        $maxConnections = 50;

        if ($planMemoryMb !== null && $planMemoryMb > 0) {
            $databaseMb = self::databaseMemoryMb($planMemoryMb);
            $bufferPoolMb = max(128, min(2048, (int) floor($databaseMb * 0.6)));
            $maxConnections = max(50, min(300, (int) floor($databaseMb / 4)));
        }

        return [
            '--innodb-buffer-pool-size='.$bufferPoolMb.'M',
            '--max-connections='.$maxConnections,
            '--table-open-cache=200',
            '--performance-schema=OFF',
            '--innodb-use-native-aio=0',
        ];
    }

    public function syncEmbeddedDatabaseSidecar(
        array &$compose,
        object $template,
        array $envVars,
        string $appServiceName,
        ?int $planMemoryMb = null,
    ): void {
        $this->inheritEnvironmentIntoSameImageSidecars($compose, $template, $envVars, $appServiceName);
        $this->syncChatwootSidecars($compose, $template, $envVars);
        $this->syncErpnextSidecars($compose, $template, $envVars);

        if (($template->slug ?? '') === 'ospos' && isset($compose['services']['db'])) {
            $this->embeddedDatabases->reconcile($compose, 'db', $appServiceName, [
                'database' => (string) ($envVars['MYSQL_DB_NAME'] ?? 'ospos'),
                'user' => (string) ($envVars['MYSQL_USERNAME'] ?? 'ospos'),
                'password' => (string) ($envVars['MYSQL_PASSWORD'] ?? ''),
                'root_password' => (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? ''),
            ], $planMemoryMb, 'Open Source POS deploy');

            return;
        }

        if (($template->slug ?? '') !== 'wordpress' || ! isset($compose['services']['mysql'])) {
            return;
        }

        $this->embeddedDatabases->reconcile($compose, 'mysql', $appServiceName, [
            'database' => (string) ($envVars['WORDPRESS_DB_NAME'] ?? 'wordpress'),
            'user' => (string) ($envVars['WORDPRESS_DB_USER'] ?? 'wordpress'),
            'password' => (string) ($envVars['WORDPRESS_DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? ''),
            'root_password' => (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? ''),
        ], $planMemoryMb, 'WordPress deploy');
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function fillMissingRequiredVariables(object $template, array $env, Service $service, ?int $port): array
    {
        foreach ($template->environment_variables ?? [] as $var) {
            if (! is_array($var)) {
                continue;
            }

            $key = (string) ($var['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $current = trim((string) ($env[$key] ?? ''));
            if ($current !== '') {
                continue;
            }

            $required = (bool) ($var['required'] ?? false);
            $secret = (bool) ($var['secret'] ?? false);

            if ($secret) {
                $env[$key] = $this->generateSecretValue($key);

                continue;
            }

            if ($required) {
                $generated = $this->generateRequiredValue($key, $template, $service, $port);
                if ($generated !== '') {
                    $env[$key] = $generated;
                }
            }
        }

        if (($template->slug ?? '') === 'strapi') {
            $env = $this->prepareStrapiEnvironment($env);
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareWordPressEnvironment(array $env): array
    {
        if (trim((string) ($env['WORDPRESS_DB_PASSWORD'] ?? '')) === '') {
            $env['WORDPRESS_DB_PASSWORD'] = Str::random(32);
        }

        if (trim((string) ($env['WORDPRESS_ADMIN_PASSWORD'] ?? '')) === '') {
            $env['WORDPRESS_ADMIN_PASSWORD'] = Str::random(20);
        }

        if (trim((string) ($env['MYSQL_ROOT_PASSWORD'] ?? '')) === '') {
            $env['MYSQL_ROOT_PASSWORD'] = Str::random(32);
        }

        return $env;
    }

    /**
     * Python web apps build their settings object at import time, so a missing
     * signing key or synchronous database URL crashes uvicorn before it serves
     * a request. Supply the two the platform can derive on its own; anything
     * tied to a third-party account stays the customer's to provide.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function preparePythonEnvironment(array $env): array
    {
        $env['PYTHONUNBUFFERED'] = $this->filledOr($env, 'PYTHONUNBUFFERED', '1');

        if (trim((string) ($env['SECRET_KEY'] ?? '')) === '') {
            $env['SECRET_KEY'] = Str::random(50);
        }

        $databaseUrl = trim((string) ($env['DATABASE_URL'] ?? ''));
        if ($databaseUrl !== '' && trim((string) ($env['SYNC_DATABASE_URL'] ?? '')) === '') {
            $env['SYNC_DATABASE_URL'] = $this->synchronousDatabaseUrl($databaseUrl);
        }

        return $env;
    }

    /**
     * SQLAlchemy reads a bare scheme as the synchronous driver; async stacks pin
     * one explicitly (postgresql+asyncpg). Alembic and other synchronous callers
     * need that suffix gone.
     */
    private function synchronousDatabaseUrl(string $databaseUrl): string
    {
        return preg_replace('/^([A-Za-z0-9]+)\+[A-Za-z0-9_]+:\/\//', '$1://', $databaseUrl) ?? $databaseUrl;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareStrapiEnvironment(array $env): array
    {
        if (trim((string) ($env['APP_KEYS'] ?? '')) === '') {
            $env['APP_KEYS'] = implode(',', [
                Str::random(32),
                Str::random(32),
                Str::random(32),
                Str::random(32),
            ]);
        }

        foreach (['API_TOKEN_SALT', 'ADMIN_JWT_SECRET', 'JWT_SECRET', 'TRANSFER_TOKEN_SALT'] as $key) {
            if (trim((string) ($env[$key] ?? '')) === '') {
                $env[$key] = Str::random(32);
            }
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareHermesEnvironment(array $env, Service $service): array
    {
        $env['HERMES_DASHBOARD'] = $this->filledOr($env, 'HERMES_DASHBOARD', '1');
        $env['HERMES_DASHBOARD_HOST'] = $this->filledOr($env, 'HERMES_DASHBOARD_HOST', '0.0.0.0');
        $env['HERMES_DASHBOARD_BASIC_AUTH_USERNAME'] = $this->filledOr($env, 'HERMES_DASHBOARD_BASIC_AUTH_USERNAME', 'admin');
        $env['API_SERVER_ENABLED'] = $this->filledOr($env, 'API_SERVER_ENABLED', 'true');
        $env['API_SERVER_HOST'] = $this->filledOr($env, 'API_SERVER_HOST', '0.0.0.0');

        if (trim((string) ($env['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD'] ?? '')) === '') {
            $env['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD'] = Str::random(24);
        }

        if (trim((string) ($env['HERMES_DASHBOARD_BASIC_AUTH_SECRET'] ?? '')) === '') {
            $env['HERMES_DASHBOARD_BASIC_AUTH_SECRET'] = Str::random(32);
        }

        if (trim((string) ($env['API_SERVER_KEY'] ?? '')) === '') {
            $env['API_SERVER_KEY'] = Str::random(32);
        }

        $publicUrl = null;
        if ($service->relationLoaded('containerDeployment')) {
            $deployment = $service->getRelation('containerDeployment');
            $accessUrl = $deployment?->getAccessUrl();
            if (is_string($accessUrl) && $accessUrl !== '') {
                $publicUrl = rtrim($accessUrl, '/');
            }
        }

        if ($publicUrl !== null) {
            $env['HERMES_DASHBOARD_PUBLIC_URL'] = $publicUrl;
        }

        // Nginx on the host reaches the published port via the Docker bridge,
        // not loopback. Chat WebSockets (/api/ws, /api/pty) reject untrusted
        // peers and X-Forwarded-* from those addresses unless they are listed.
        $env['FORWARDED_ALLOW_IPS'] = $this->filledOr(
            $env,
            'FORWARDED_ALLOW_IPS',
            '127.0.0.1,::1,172.16.0.0/12,10.0.0.0/8'
        );
        $env['HERMES_WS_PING_INTERVAL'] = $this->filledOr($env, 'HERMES_WS_PING_INTERVAL', '30');
        $env['HERMES_WS_PING_TIMEOUT'] = $this->filledOr($env, 'HERMES_WS_PING_TIMEOUT', '120');
        $env['HERMES_WS_WRITE_TIMEOUT'] = $this->filledOr($env, 'HERMES_WS_WRITE_TIMEOUT', '180');

        return $env;
    }

    /**
     * @return array{
     *     url: ?string,
     *     username: string,
     *     password: string,
     *     container_running: bool
     * }|null
     */
    public function hermesDashboardPanel(Service $service, ?ContainerDeployment $deployment): ?array
    {
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'hermes') {
            return null;
        }

        $env = is_array($deployment?->env_values) ? $deployment->env_values : [];

        return [
            'url' => $deployment?->getAccessUrl(),
            'username' => trim((string) ($env['HERMES_DASHBOARD_BASIC_AUTH_USERNAME'] ?? 'admin')) ?: 'admin',
            'password' => (string) ($env['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD'] ?? ''),
            'container_running' => (bool) $deployment?->isRunning(),
        ];
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareOpenClawEnvironment(array $env): array
    {
        $env['OPENCLAW_GATEWAY_BIND'] = $this->filledOr($env, 'OPENCLAW_GATEWAY_BIND', 'lan');
        $env['HOME'] = $this->filledOr($env, 'HOME', '/home/node');
        $env['OPENCLAW_STATE_DIR'] = $this->filledOr($env, 'OPENCLAW_STATE_DIR', '/home/node/.openclaw');
        $env['OPENCLAW_CONFIG_DIR'] = $this->filledOr($env, 'OPENCLAW_CONFIG_DIR', '/home/node/.openclaw');
        $env['OPENCLAW_CONFIG_PATH'] = $this->filledOr($env, 'OPENCLAW_CONFIG_PATH', '/home/node/.openclaw/openclaw.json');
        $env['OPENCLAW_WORKSPACE_DIR'] = $this->filledOr($env, 'OPENCLAW_WORKSPACE_DIR', '/home/node/.openclaw/workspace');
        $env['OPENCLAW_DISABLE_BONJOUR'] = $this->filledOr($env, 'OPENCLAW_DISABLE_BONJOUR', '1');

        if (trim((string) ($env['OPENCLAW_GATEWAY_TOKEN'] ?? '')) === '') {
            $env['OPENCLAW_GATEWAY_TOKEN'] = Str::random(32);
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function filledOr(array $env, string $key, string $default): string
    {
        $value = trim((string) ($env[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function generateSecretValue(string $key): string
    {
        if ($key === 'APP_KEYS') {
            return implode(',', [Str::random(32), Str::random(32), Str::random(32), Str::random(32)]);
        }

        return Str::random(32);
    }

    private function generateRequiredValue(string $key, object $template, Service $service, ?int $port): string
    {
        if ($key === 'url' && ($template->slug ?? '') === 'ghost') {
            return $port ? "http://localhost:{$port}" : 'http://localhost';
        }

        if ($key === 'mail__from') {
            $email = trim((string) ($service->user?->email ?? ''));

            return $email !== '' ? $email : 'noreply@example.com';
        }

        if (in_array($key, ['WORDPRESS_ADMIN_EMAIL', 'ADMIN_EMAIL'], true)) {
            return trim((string) ($service->user?->email ?? '')) ?: 'admin@example.com';
        }

        return '';
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareN8nEnvironment(array $env, ?int $port): array
    {
        $env['N8N_PORT'] = $this->filledOr($env, 'N8N_PORT', '5678');
        $env['N8N_PROTOCOL'] = $this->filledOr($env, 'N8N_PROTOCOL', 'https');
        $env['N8N_PROXY_HOPS'] = $this->filledOr($env, 'N8N_PROXY_HOPS', '1');
        $env['N8N_BASIC_AUTH_ACTIVE'] = $this->filledOr($env, 'N8N_BASIC_AUTH_ACTIVE', 'true');
        $env['N8N_BASIC_AUTH_USER'] = $this->filledOr($env, 'N8N_BASIC_AUTH_USER', 'admin');
        $env['GENERIC_TIMEZONE'] = $this->filledOr($env, 'GENERIC_TIMEZONE', 'Africa/Nairobi');
        $env['N8N_HOST'] = $this->filledOr($env, 'N8N_HOST', $this->publicHost($port));
        $env['WEBHOOK_URL'] = $this->filledOr($env, 'WEBHOOK_URL', $this->publicUrl($port));

        if (trim((string) ($env['N8N_ENCRYPTION_KEY'] ?? '')) === '') {
            $env['N8N_ENCRYPTION_KEY'] = Str::random(32);
        }

        if (trim((string) ($env['N8N_BASIC_AUTH_PASSWORD'] ?? '')) === '') {
            $env['N8N_BASIC_AUTH_PASSWORD'] = Str::random(24);
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareDirectusEnvironment(array $env, Service $service): array
    {
        $env['ADMIN_EMAIL'] = $this->filledOr(
            $env,
            'ADMIN_EMAIL',
            trim((string) ($service->user?->email ?? '')) ?: 'admin@example.com'
        );
        $env['DB_CLIENT'] = $this->filledOr($env, 'DB_CLIENT', $this->directusClient($env));
        $env['DB_HOST'] = $this->filledOr($env, 'DB_HOST', 'db');
        $env['DB_USER'] = $this->filledOr($env, 'DB_USER', (string) ($env['DB_USERNAME'] ?? 'appuser'));
        $env['DB_PASSWORD'] = $this->filledOr($env, 'DB_PASSWORD', (string) ($env['DB_PASSWORD'] ?? ''));
        $env['DB_DATABASE'] = $this->filledOr($env, 'DB_DATABASE', (string) ($env['DB_DATABASE'] ?? 'appdb'));
        $env['DB_PORT'] = $this->filledOr($env, 'DB_PORT', $this->directusClient($env) === 'pg' ? '5432' : '3306');

        if (trim((string) ($env['SECRET'] ?? '')) === '') {
            $env['SECRET'] = Str::random(40);
        }

        if (trim((string) ($env['ADMIN_PASSWORD'] ?? '')) === '') {
            $env['ADMIN_PASSWORD'] = Str::random(20);
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function directusClient(array $env): string
    {
        $connection = strtolower((string) ($env['DB_CONNECTION'] ?? ''));

        return in_array($connection, ['pgsql', 'postgres', 'postgresql', 'pg'], true) ? 'pg' : 'mysql';
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareChatwootEnvironment(array $env, Service $service, ?int $port): array
    {
        $env['RAILS_ENV'] = $this->filledOr($env, 'RAILS_ENV', 'production');
        $env['NODE_ENV'] = $this->filledOr($env, 'NODE_ENV', 'production');
        $env['INSTALLATION_ENV'] = $this->filledOr($env, 'INSTALLATION_ENV', 'docker');
        $env['FRONTEND_URL'] = $this->filledOr($env, 'FRONTEND_URL', $this->publicUrl($port));
        $env['POSTGRES_HOST'] = $this->filledOr($env, 'POSTGRES_HOST', (string) ($env['DB_HOST'] ?? 'db'));
        $env['POSTGRES_PORT'] = $this->filledOr($env, 'POSTGRES_PORT', (string) ($env['DB_PORT'] ?? '5432'));
        $env['POSTGRES_DATABASE'] = $this->filledOr($env, 'POSTGRES_DATABASE', (string) ($env['DB_DATABASE'] ?? $env['POSTGRES_DB'] ?? 'appdb'));
        $env['POSTGRES_USERNAME'] = $this->filledOr($env, 'POSTGRES_USERNAME', (string) ($env['DB_USERNAME'] ?? $env['POSTGRES_USER'] ?? 'appuser'));
        $env['POSTGRES_PASSWORD'] = $this->filledOr($env, 'POSTGRES_PASSWORD', (string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));
        $env['REDIS_URL'] = $this->filledOr($env, 'REDIS_URL', 'redis://redis:6379');
        $env['MAILER_SENDER_EMAIL'] = $this->filledOr(
            $env,
            'MAILER_SENDER_EMAIL',
            trim((string) ($service->user?->email ?? '')) ?: 'noreply@example.com'
        );

        if (trim((string) ($env['SECRET_KEY_BASE'] ?? '')) === '') {
            $env['SECRET_KEY_BASE'] = Str::random(64);
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareOdooEnvironment(array $env): array
    {
        $env['HOST'] = $this->filledOr($env, 'HOST', (string) ($env['DB_HOST'] ?? 'db'));
        $env['PORT'] = $this->filledOr($env, 'PORT', (string) ($env['DB_PORT'] ?? '5432'));
        $env['USER'] = $this->filledOr($env, 'USER', (string) ($env['DB_USERNAME'] ?? $env['POSTGRES_USER'] ?? 'appuser'));
        $env['PASSWORD'] = $this->filledOr($env, 'PASSWORD', (string) ($env['DB_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? ''));

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareErpnextEnvironment(array $env): array
    {
        $env['DB_HOST'] = $this->filledOr($env, 'DB_HOST', 'db');
        $env['DB_PORT'] = $this->filledOr($env, 'DB_PORT', '3306');
        $env['BACKEND'] = $this->filledOr($env, 'BACKEND', 'backend:8000');
        $env['SOCKETIO'] = $this->filledOr($env, 'SOCKETIO', 'websocket:9000');
        $env['FRAPPE_SITE_NAME_HEADER'] = $this->filledOr($env, 'FRAPPE_SITE_NAME_HEADER', 'frontend');
        $env['FRAPPE_REDIS_CACHE'] = $this->filledOr($env, 'FRAPPE_REDIS_CACHE', 'redis://redis-cache:6379');
        $env['FRAPPE_REDIS_QUEUE'] = $this->filledOr($env, 'FRAPPE_REDIS_QUEUE', 'redis://redis-queue:6379');

        if (trim((string) ($env['MYSQL_ROOT_PASSWORD'] ?? '')) === '') {
            $env['MYSQL_ROOT_PASSWORD'] = Str::random(32);
        }

        $env['MARIADB_ROOT_PASSWORD'] = $this->filledOr($env, 'MARIADB_ROOT_PASSWORD', $env['MYSQL_ROOT_PASSWORD']);

        if (trim((string) ($env['ERPNEXT_ADMIN_PASSWORD'] ?? '')) === '') {
            $env['ERPNEXT_ADMIN_PASSWORD'] = Str::random(20);
        }

        return $env;
    }

    /**
     * Open Source POS reads its database and security settings straight from
     * the process environment. Credentials and the encryption key are generated
     * once and then kept (filledOr / the empty checks), the Host allow-list is
     * platform-owned and recomputed every time, and DB_* mirrors exist for the
     * console's database tab, credential repair and dump import.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareOsposEnvironment(array $env, Service $service): array
    {
        $env['CI_ENVIRONMENT'] = $this->filledOr($env, 'CI_ENVIRONMENT', 'production');
        $env['PHP_TIMEZONE'] = $this->filledOr($env, 'PHP_TIMEZONE', 'Africa/Nairobi');
        $env['FORCE_HTTPS'] = $this->filledOr($env, 'FORCE_HTTPS', 'true');
        $env['MYSQL_HOST_NAME'] = $this->filledOr($env, 'MYSQL_HOST_NAME', 'db');
        $env['MYSQL_DB_NAME'] = $this->filledOr($env, 'MYSQL_DB_NAME', 'ospos');
        $env['MYSQL_USERNAME'] = $this->filledOr($env, 'MYSQL_USERNAME', 'ospos');

        if (trim((string) ($env['MYSQL_PASSWORD'] ?? '')) === '') {
            $env['MYSQL_PASSWORD'] = Str::random(32);
        }
        if (trim((string) ($env['MYSQL_ROOT_PASSWORD'] ?? '')) === '') {
            $env['MYSQL_ROOT_PASSWORD'] = Str::random(32);
        }
        // OSPOS does not generate one; without it sessions and stored secrets
        // would be unreadable after every restart.
        if (trim((string) ($env['ENCRYPTION_KEY'] ?? '')) === '') {
            $env['ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
        }

        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_HOST'] = $env['MYSQL_HOST_NAME'];
        $env['DB_PORT'] = '3306';
        $env['DB_DATABASE'] = $env['MYSQL_DB_NAME'];
        $env['DB_USERNAME'] = $env['MYSQL_USERNAME'];
        $env['DB_PASSWORD'] = $env['MYSQL_PASSWORD'];

        $env[ContainerAllowedHostnamesSync::HOSTNAME_ENV_KEYS['ospos']] = $this->hostnames->commaList($service);

        return $env;
    }

    private function publicUrl(?int $port): string
    {
        return $port ? "http://localhost:{$port}" : 'http://localhost';
    }

    private function publicHost(?int $port): string
    {
        return $port ? "localhost:{$port}" : 'localhost';
    }

    /**
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     */
    private function inheritEnvironmentIntoSameImageSidecars(
        array &$compose,
        object $template,
        array $envVars,
        string $appServiceName
    ): void {
        $image = (string) ($template->docker_image ?? '');
        if ($image === '' || ! isset($compose['services']) || ! is_array($compose['services'])) {
            return;
        }

        foreach ($compose['services'] as $name => $service) {
            if ($name === $appServiceName || ! is_array($service)) {
                continue;
            }

            if (($service['image'] ?? '') !== $image) {
                continue;
            }

            $existing = is_array($service['environment'] ?? null) ? $service['environment'] : [];
            $compose['services'][$name]['environment'] = array_merge($existing, $envVars);
        }
    }

    /**
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     */
    private function syncChatwootSidecars(array &$compose, object $template, array $envVars): void
    {
        if (($template->slug ?? '') !== 'chatwoot' || ! isset($compose['services']['sidekiq'])) {
            return;
        }

        $depends = ['redis'];
        if (isset($compose['services']['db'])) {
            $depends[] = 'db';
        }

        $compose['services']['sidekiq']['depends_on'] = $depends;
        $compose['services']['sidekiq']['environment'] = array_merge(
            is_array($compose['services']['sidekiq']['environment'] ?? null)
                ? $compose['services']['sidekiq']['environment']
                : [],
            $envVars
        );
    }

    /**
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     */
    private function syncErpnextSidecars(array &$compose, object $template, array $envVars): void
    {
        if (($template->slug ?? '') !== 'erpnext' || ! isset($compose['services']['db'])) {
            return;
        }

        $rootPassword = trim((string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? ''));
        $adminPassword = trim((string) ($envVars['ERPNEXT_ADMIN_PASSWORD'] ?? 'admin'));
        if ($rootPassword === '') {
            throw new \RuntimeException('ERPNext deploy is missing MYSQL_ROOT_PASSWORD before composing the MariaDB sidecar.');
        }

        $compose['services']['db']['environment'] = [
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
            'MARIADB_ROOT_PASSWORD' => $rootPassword,
        ];

        $siteExists = '[ -d sites/frontend ]';
        $create = 'bench new-site --mariadb-user-host-login-scope=% --admin-password='
            .escapeshellarg($adminPassword)
            .' --db-root-username=root --db-root-password='
            .escapeshellarg($rootPassword)
            .' --install-app erpnext --set-default frontend';

        if (isset($compose['services']['create-site'])) {
            $compose['services']['create-site']['environment'] = array_merge(
                is_array($compose['services']['create-site']['environment'] ?? null)
                    ? $compose['services']['create-site']['environment']
                    : [],
                $envVars
            );
            $compose['services']['create-site']['entrypoint'] = ['bash', '-c'];
            $compose['services']['create-site']['command'] = [
                'wait-for-it -t 180 db:3306; if '.$siteExists.'; then echo site-exists; else '.$create.'; fi',
            ];
        }
    }
}
