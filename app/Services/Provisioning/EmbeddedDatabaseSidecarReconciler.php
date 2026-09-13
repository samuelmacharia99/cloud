<?php

namespace App\Services\Provisioning;

/**
 * Brings a database service a template ships in its own compose_services in
 * line with the platform's sidecar conventions.
 *
 * A template row is shared by every customer, so what it carries is a
 * placeholder: a static container name that would collide across stacks,
 * throw-away credentials, no healthcheck, no memory sizing. This rewrites the
 * service for one deployment: unique container name and network alias, the
 * real credentials from the deployment's environment, plan-sized MySQL flags,
 * a TCP healthcheck, and a start-ordering dependency from the app.
 *
 * Credentials are never invented here. They must already be in env_values,
 * otherwise the running database and the recorded environment would disagree
 * and every later credential probe, import and repair would use the wrong
 * secret. WordPress (`mysql`) and Open Source POS (`db`) both go through this.
 */
class EmbeddedDatabaseSidecarReconciler
{
    /**
     * @param  array<string, mixed>  $compose
     * @param  array{database: string, user: string, password: string, root_password: string}  $credentials
     *
     * @throws \RuntimeException when a credential is missing
     */
    public function reconcile(
        array &$compose,
        string $serviceKey,
        string $appServiceName,
        array $credentials,
        ?int $planMemoryMb = null,
        string $stackLabel = 'Deploy',
    ): void {
        if (! isset($compose['services'][$serviceKey]) || ! is_array($compose['services'][$serviceKey])) {
            return;
        }

        $rootPassword = trim((string) ($credentials['root_password'] ?? ''));
        $password = trim((string) ($credentials['password'] ?? ''));
        if ($rootPassword === '' || $password === '') {
            throw new \RuntimeException(
                "{$stackLabel} is missing the database root or user password before composing the {$serviceKey} sidecar."
            );
        }

        $service = &$compose['services'][$serviceKey];

        $service['environment'] = [
            'MYSQL_DATABASE' => (string) ($credentials['database'] ?? 'app'),
            'MYSQL_USER' => (string) ($credentials['user'] ?? 'app'),
            'MYSQL_PASSWORD' => $password,
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
        ];

        // Avoid colliding container names across customers (template default is static).
        $service['container_name'] = $this->containerNameFor($serviceKey, $appServiceName);

        // Host reboots / docker restarts: always bring the DB back even if it was stopped for maintenance.
        $service['restart'] = 'always';

        // This is a soft reservation, not a kill threshold. The final compose resource
        // policy normalizes app + database reservations to the plan's included resources.
        unset($service['mem_limit'], $service['cpus']);
        $service['mem_reservation'] = '256M';

        // Sized from the plan, not fixed. A fixed 256M pool meant a customer on
        // four gigabytes ran the same database as one on one gigabyte.
        $service['command'] = ContainerTemplateEnvironmentService::tuningFlagsFor($planMemoryMb);

        $service['networks'] = [
            'default' => [
                'aliases' => [$service['container_name']],
            ],
        ];

        // Use TCP (127.0.0.1), not the unix socket — during InnoDB recovery the sock is often missing
        // and healthchecks fail with "Can't connect ... mysqld.sock". Long start_period covers reboot recovery.
        $service['healthcheck'] = [
            'test' => [
                'CMD-SHELL',
                'mysqladmin ping -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent',
            ],
            'interval' => '10s',
            'timeout' => '5s',
            'retries' => 30,
            'start_period' => '300s',
        ];
        unset($service);

        // Keep the healthcheck for ops visibility, but do not block the app
        // container on service_healthy — InnoDB recovery can take minutes and left
        // sites on 504 while compose waited. The applications retry DB connections.
        $compose['services'][$appServiceName]['restart'] = 'always';
        $compose['services'][$appServiceName]['depends_on'] = [
            $serviceKey => ['condition' => 'service_started'],
        ];
    }

    /**
     * WordPress historically named its sidecar `{app}-mysql`; everything else
     * follows the injected sidecar's `{app}-db`, which is what sidecarDnsHost(),
     * the credential probes and the GRANT sync expect.
     */
    public function containerNameFor(string $serviceKey, string $appServiceName): string
    {
        return $serviceKey === 'mysql' ? $appServiceName.'-mysql' : $appServiceName.'-db';
    }
}
