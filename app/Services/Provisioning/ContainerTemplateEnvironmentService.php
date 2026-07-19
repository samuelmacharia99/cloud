<?php

namespace App\Services\Provisioning;

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

        // Cap MySQL for shared container hosts (many WP stacks per node).
        // 1g + 512M buffer pool per site caused host pressure → slow MySQL → nginx 504s.
        $compose['services']['mysql']['mem_limit'] = '512M';
        $compose['services']['mysql']['cpus'] = $compose['services']['mysql']['cpus'] ?? 1.0;

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

        if ($key === 'WORDPRESS_ADMIN_EMAIL') {
            return trim((string) ($service->user?->email ?? '')) ?: 'admin@example.com';
        }

        return '';
    }
}
