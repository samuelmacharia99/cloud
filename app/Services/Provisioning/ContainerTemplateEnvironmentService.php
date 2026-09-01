<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use Illuminate\Support\Str;

class ContainerTemplateEnvironmentService
{
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

        if (($template->slug ?? '') === 'ollama') {
            $env = $this->prepareOllamaEnvironment($env, $service);
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
    public function syncEmbeddedDatabaseSidecar(array &$compose, object $template, array $envVars, string $appServiceName): void
    {
        $this->inheritEnvironmentIntoSameImageSidecars($compose, $template, $envVars, $appServiceName);
        $this->syncChatwootSidecars($compose, $template, $envVars);
        $this->syncErpnextSidecars($compose, $template, $envVars);

        if (($template->slug ?? '') !== 'wordpress' || ! isset($compose['services']['mysql'])) {
            return;
        }

        $rootPassword = trim((string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? ''));
        $mysqlPassword = trim((string) ($envVars['WORDPRESS_DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? ''));

        // Never invent passwords here — they would not be saved to deployment env_values
        // and import would use a different secret than the running MySQL container.
        if ($rootPassword === '' || $mysqlPassword === '') {
            throw new \RuntimeException(
                'WordPress deploy is missing MYSQL_ROOT_PASSWORD / WORDPRESS_DB_PASSWORD before composing the mysql sidecar.'
            );
        }

        $compose['services']['mysql']['environment'] = [
            'MYSQL_DATABASE' => $envVars['WORDPRESS_DB_NAME'] ?? 'wordpress',
            'MYSQL_USER' => $envVars['WORDPRESS_DB_USER'] ?? 'wordpress',
            'MYSQL_PASSWORD' => $mysqlPassword,
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
        ];

        // Avoid colliding container names across customers (template default is static).
        $compose['services']['mysql']['container_name'] = $appServiceName.'-mysql';

        // Host reboots / docker restarts: always bring the DB back even if it was stopped for maintenance.
        $compose['services']['mysql']['restart'] = 'always';

        // This is a soft reservation, not a kill threshold. The final compose resource
        // policy normalizes app + MySQL reservations to the plan's included resources.
        unset($compose['services']['mysql']['mem_limit'], $compose['services']['mysql']['cpus']);
        $compose['services']['mysql']['mem_reservation'] = '256M';

        // Keep InnoDB comfortably inside the 512M container budget.
        $compose['services']['mysql']['command'] = [
            '--innodb-buffer-pool-size=256M',
            '--max-connections=50',
            '--table-open-cache=200',
            '--performance-schema=OFF',
        ];

        // Use TCP (127.0.0.1), not the unix socket — during InnoDB recovery the sock is often missing
        // and healthchecks fail with "Can't connect ... mysqld.sock". Long start_period covers reboot recovery.
        $compose['services']['mysql']['healthcheck'] = [
            'test' => [
                'CMD-SHELL',
                'mysqladmin ping -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent',
            ],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 30,
            'start_period' => '300s',
        ];

        // Keep the healthcheck for Portainer/ops visibility, but do not block the app
        // container on service_healthy — InnoDB recovery can take minutes and left sites
        // on 504 while compose waited. WordPress retries DB connections itself.
        $compose['services'][$appServiceName]['restart'] = 'always';
        $compose['services'][$appServiceName]['depends_on'] = [
            'mysql' => ['condition' => 'service_started'],
        ];
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
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepareOllamaEnvironment(array $env, Service $service): array
    {
        $env['OLLAMA_HOST'] = $this->filledOr($env, 'OLLAMA_HOST', '0.0.0.0:11434');
        $env['OLLAMA_KEEP_ALIVE'] = $this->filledOr($env, 'OLLAMA_KEEP_ALIVE', '24h');
        $env['OLLAMA_CONTEXT_LENGTH'] = $this->filledOr(
            $env,
            'OLLAMA_CONTEXT_LENGTH',
            (string) ContainerOllamaModelService::AGENT_CONTEXT_LENGTH
        );
        $env['OLLAMA_NUM_CTX'] = $this->filledOr(
            $env,
            'OLLAMA_NUM_CTX',
            (string) ContainerOllamaModelService::AGENT_CONTEXT_LENGTH
        );

        $selectedVersion = is_array($service->service_meta)
            ? ($service->service_meta['selected_version'] ?? null)
            : null;

        $env['OLLAMA_MODEL'] = $this->filledOr(
            $env,
            'OLLAMA_MODEL',
            ContainerOllamaModelService::modelTag(is_string($selectedVersion) ? $selectedVersion : null)
        );

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
