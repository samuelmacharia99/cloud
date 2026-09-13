<?php

namespace App\Services\Provisioning;

use App\Enums\StackMemberKind;
use App\Models\ContainerDeployment;
use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a service's rendered compose file into the list of containers the
 * customer's project page shows as one folder.
 *
 * Pure: it reads the deployment row that is already loaded on the service
 * and never touches the node. State is filled in afterwards by
 * StackMemberStateService from the cached snapshot.
 *
 * Classification follows the same rules the deploy path already uses to
 * tell the app service from its sidecars (ContainerDeploymentService::
 * resolveComposeAppServiceKey / composeDefinesDatabaseSidecar), so a
 * container is a "database" here exactly when it is one there.
 */
final class StackMemberResolver
{
    public const DATABASE_KEYS = ['db', 'mysql', 'mariadb', 'postgres', 'postgresql', 'mongo', 'mongodb'];

    public const CACHE_KEYS = ['redis', 'cache', 'memcached', 'valkey'];

    public const WORKER_KEYS = ['sidekiq', 'worker', 'queue', 'scheduler', 'horizon', 'celery', 'cron', 'websocket'];

    /** Keys the app-service fallback skips, mirroring resolveComposeAppServiceKey. */
    private const NON_APP_KEYS = ['db', 'mysql', 'mariadb', 'postgres', 'postgresql', 'redis', 'cache', 'mail'];

    /**
     * @return list<StackMember>
     */
    public function membersForService(Service $service): array
    {
        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;

        if (! $deployment) {
            return $this->synthesizedMembers($service, StackMemberState::pending());
        }

        $yaml = (string) ($deployment->docker_compose_content ?? '');
        if (trim($yaml) === '') {
            return $this->synthesizedMembers($service, StackMemberState::pending());
        }

        try {
            $compose = Yaml::parse($yaml);
        } catch (\Throwable) {
            return $this->synthesizedMembers($service, StackMemberState::unknown());
        }

        if (! is_array($compose) || ! is_array($compose['services'] ?? null) || $compose['services'] === []) {
            return $this->synthesizedMembers($service, StackMemberState::unknown());
        }

        return $this->membersFromCompose($compose, (string) $deployment->container_name, $service);
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return list<StackMember>
     */
    public function membersFromCompose(array $compose, string $containerName, Service $service): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $appKey = $this->appServiceKey($services, $containerName);
        $members = [];

        foreach ($services as $key => $definition) {
            $key = (string) $key;
            $definition = is_array($definition) ? $definition : [];
            $kind = $this->classify($key, $definition, $appKey, $service);

            $members[] = new StackMember(
                composeKey: $key,
                kind: $kind,
                label: $this->labelFor($key, $kind),
                containerName: $this->containerNameFor($key, $definition, $containerName),
                serviceId: (int) $service->id,
                databaseType: $kind === StackMemberKind::Database ? $this->databaseTypeFor($key, $definition) : null,
                state: StackMemberState::unknown(),
            );
        }

        return $this->sorted($members);
    }

    /**
     * Members of a stack that has no readable compose file: what the deploy
     * will create, from the intent recorded on the service and its template.
     *
     * @return list<StackMember>
     */
    public function synthesizedMembers(Service $service, ?StackMemberState $state = null): array
    {
        $state ??= StackMemberState::pending();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $containerName = (string) ($service->containerDeployment?->container_name ?? 'pending');
        $members = [];

        $make = function (string $key, StackMemberKind $kind, ?string $databaseType = null) use ($service, $containerName, $state): StackMember {
            return new StackMember(
                composeKey: $key,
                kind: $kind,
                label: $this->labelFor($key, $kind),
                containerName: $this->containerNameFor($key, [], $containerName),
                serviceId: (int) $service->id,
                databaseType: $databaseType,
                state: $state,
                synthesized: true,
            );
        };

        if ($this->intendsLaravelNextStack($meta)) {
            $members[] = $make('backend', StackMemberKind::Backend);
            $members[] = $make('frontend', StackMemberKind::Frontend);
            $members[] = $make('edge', StackMemberKind::Edge);
            $database = strtolower((string) ($meta['database'] ?? $meta['database_id'] ?? ''));
            if ($database !== '' && ! in_array($database, ['none', 'sqlite'], true)) {
                $members[] = $make('db', StackMemberKind::Database);
            }

            return $members;
        }

        $members[] = $make('app', $this->roleKind($meta) ?? StackMemberKind::App);

        $template = $service->effectiveContainerTemplate();
        $composeServices = is_array($template?->compose_services) ? $template->compose_services : [];
        foreach ($composeServices as $key => $definition) {
            $key = (string) $key;
            $definition = is_array($definition) ? $definition : [];
            $kind = $this->classify($key, $definition, 'app', $service);
            $members[] = $make($key, $kind, $kind === StackMemberKind::Database ? $this->databaseTypeFor($key, $definition) : null);
        }

        return $this->sorted($members);
    }

    public function databaseMember(Service $service): ?StackMember
    {
        foreach ($this->membersForService($service) as $member) {
            if ($member->kind === StackMemberKind::Database) {
                return $member;
            }
        }

        return null;
    }

    public function member(Service $service, string $composeKey): ?StackMember
    {
        foreach ($this->membersForService($service) as $member) {
            if ($member->composeKey === $composeKey) {
                return $member;
            }
        }

        return null;
    }

    public function hasDatabaseMember(ContainerDeployment $deployment): bool
    {
        $deployment->loadMissing('service');
        if (! $deployment->service) {
            return false;
        }

        foreach ($this->membersForService($deployment->service) as $member) {
            if ($member->kind === StackMemberKind::Database && ! $member->synthesized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function classify(string $key, array $definition, ?string $appKey, Service $service): StackMemberKind
    {
        $key = strtolower($key);
        $image = strtolower((string) ($definition['image'] ?? ''));
        $cname = strtolower((string) ($definition['container_name'] ?? ''));

        if ($appKey !== null && $key === strtolower($appKey)) {
            if ($key === 'backend') {
                return StackMemberKind::Backend;
            }

            return $this->roleKind(is_array($service->service_meta) ? $service->service_meta : []) ?? StackMemberKind::App;
        }

        if ($key === 'backend') {
            return StackMemberKind::Backend;
        }
        if ($key === 'frontend') {
            return StackMemberKind::Frontend;
        }
        if ($key === 'edge') {
            return StackMemberKind::Edge;
        }

        if (in_array($key, self::DATABASE_KEYS, true)
            || str_ends_with($cname, '-db') || str_ends_with($cname, '_db') || str_ends_with($cname, '-mysql')
            || preg_match('/mysql|mariadb|postgres|mongo/', $image) === 1) {
            return StackMemberKind::Database;
        }

        if (in_array($key, self::CACHE_KEYS, true) || preg_match('/redis|memcached|valkey/', $image) === 1) {
            return StackMemberKind::Cache;
        }

        if (in_array($key, self::WORKER_KEYS, true) || str_ends_with($key, '-worker') || str_ends_with($key, '_worker')) {
            return StackMemberKind::Worker;
        }

        return StackMemberKind::Other;
    }

    public function labelFor(string $key, StackMemberKind $kind): string
    {
        return match ($kind) {
            StackMemberKind::Cache => strtolower($key) === 'redis' ? 'Redis' : 'Cache',
            StackMemberKind::Worker, StackMemberKind::Other => ucfirst(str_replace(['-', '_'], ' ', $key)),
            StackMemberKind::App => strtolower($key) === 'web' ? 'Web' : 'App',
            default => $kind->label(),
        };
    }

    /**
     * Compose v2 names a service without an explicit container_name
     * "{project}-{service}-1"; the state probe also matches by service label
     * so this only has to be right for display and direct inspects.
     *
     * @param  array<string, mixed>  $definition
     */
    public function containerNameFor(string $key, array $definition, string $composeProject): string
    {
        $explicit = trim((string) ($definition['container_name'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return $composeProject.'-'.$key.'-1';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function databaseTypeFor(string $key, array $definition): ?string
    {
        $image = strtolower((string) ($definition['image'] ?? ''));

        return match (true) {
            str_contains($image, 'mariadb') => 'mariadb',
            str_contains($image, 'mysql') => 'mysql',
            str_contains($image, 'postgres') || str_contains($image, 'pgvector') || str_contains($image, 'postgis') => 'postgresql',
            str_contains($image, 'mongo') => 'mongodb',
            default => match (strtolower($key)) {
                'mysql' => 'mysql',
                'mariadb' => 'mariadb',
                'postgres', 'postgresql' => 'postgresql',
                'mongo', 'mongodb' => 'mongodb',
                default => null,
            },
        };
    }

    /**
     * @param  array<string, mixed>  $services
     */
    private function appServiceKey(array $services, string $containerName): ?string
    {
        foreach ($services as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $cname = (string) ($definition['container_name'] ?? $name);
            if ($cname === $containerName || (string) $name === $containerName) {
                return (string) $name;
            }
        }

        foreach (array_keys($services) as $name) {
            $name = (string) $name;
            if (in_array($name, self::NON_APP_KEYS, true) || str_ends_with($name, '-db') || str_ends_with($name, '_db')) {
                continue;
            }

            return $name;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function roleKind(array $meta): ?StackMemberKind
    {
        return match ($meta['project_role'] ?? null) {
            'backend' => StackMemberKind::Backend,
            'frontend' => StackMemberKind::Frontend,
            default => null,
        };
    }

    /**
     * Mirrors CustomerProjectService::intendsLaravelNextStack for a service
     * without a compose file (the compose branch of that check is moot here).
     *
     * @param  array<string, mixed>  $meta
     */
    private function intendsLaravelNextStack(array $meta): bool
    {
        if (! empty($meta['project_recipe'])) {
            return false;
        }

        if (in_array(strtolower((string) ($meta['frontend'] ?? '')), ['nextjs', 'next', 'next.js'], true)) {
            return true;
        }

        return ! empty($meta['laravel_next_sidecar']) || ! empty($meta['uses_next_sidecar']);
    }

    /**
     * Application containers first, then the database, then the rest.
     *
     * @param  list<StackMember>  $members
     * @return list<StackMember>
     */
    private function sorted(array $members): array
    {
        $rank = [
            StackMemberKind::App->value => 0,
            StackMemberKind::Backend->value => 1,
            StackMemberKind::Frontend->value => 2,
            StackMemberKind::Edge->value => 3,
            StackMemberKind::Database->value => 4,
            StackMemberKind::Cache->value => 5,
            StackMemberKind::Worker->value => 6,
            StackMemberKind::Other->value => 7,
        ];

        usort($members, fn (StackMember $a, StackMember $b) => [$rank[$a->kind->value], $a->composeKey] <=> [$rank[$b->kind->value], $b->composeKey]);

        return array_values($members);
    }
}
