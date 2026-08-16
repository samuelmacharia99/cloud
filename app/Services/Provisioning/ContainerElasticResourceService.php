<?php

namespace App\Services\Provisioning;

use Symfony\Component\Yaml\Yaml;

/**
 * Converts plan resources into scheduling reservations instead of kill thresholds.
 *
 * A service may burst above its included CPU/RAM and is billed from observed usage.
 * Physical node safety is enforced by placement headroom and node monitoring, not by
 * setting each container's hard ceiling equal to its plan allowance.
 */
class ContainerElasticResourceService
{
    public const POLICY_VERSION = 'elastic-v1';

    /**
     * @param  array<string, mixed>  $compose
     */
    public function apply(array &$compose, string $appServiceName, float $includedCpu, int $includedMemoryMb): void
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($services === []) {
            return;
        }

        $weights = $this->resourceWeights($services, $appServiceName);
        foreach ($services as $name => &$service) {
            if (! is_array($service)) {
                continue;
            }

            $weight = $weights[(string) $name] ?? (1 / max(1, count($services)));
            $memoryReservation = max(32, (int) floor($includedMemoryMb * $weight));
            $cpuReservation = max(0.02, round($includedCpu * $weight, 3));

            if (empty($service['container_name'])) {
                $service['container_name'] = (string) $name === $appServiceName
                    ? $appServiceName
                    : $appServiceName.'-'.strtolower(str_replace('_', '-', (string) $name));
            }

            unset($service['mem_limit'], $service['cpus']);
            unset($service['deploy']['resources']['limits']);

            $service['mem_reservation'] = $memoryReservation.'M';
            // Relative shares preserve plan fairness under contention while allowing bursts.
            $service['cpu_shares'] = max(2, (int) round(1024 * $cpuReservation));
            $service['deploy']['resources']['reservations'] = [
                'cpus' => (string) $cpuReservation,
                'memory' => $memoryReservation.'M',
            ];

            if (empty($service['deploy']['resources']['limits'])) {
                unset($service['deploy']['resources']['limits']);
            }
        }
        unset($service);

        $compose['services'] = $services;
        $compose['x-talksasa-resource-policy'] = [
            'version' => self::POLICY_VERSION,
            'mode' => 'soft-reservations',
            'included_cpus' => round($includedCpu, 3),
            'included_memory_mb' => $includedMemoryMb,
        ];
    }

    public function applyToYaml(
        string $yaml,
        string $appServiceName,
        float $includedCpu,
        int $includedMemoryMb
    ): string {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose)) {
            throw new \RuntimeException('Existing Docker Compose configuration is invalid.');
        }

        $this->apply($compose, $appServiceName, $includedCpu, $includedMemoryMb);

        return Yaml::dump($compose, 10, 2);
    }

    public function isCurrent(string $yaml): bool
    {
        if (! str_contains($yaml, self::POLICY_VERSION)) {
            return false;
        }

        try {
            $compose = Yaml::parse($yaml);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return false;
        }

        foreach ($compose['services'] as $service) {
            if (! is_array($service)) {
                continue;
            }

            if (isset($service['mem_limit']) || isset($service['cpus'])) {
                return false;
            }
            if (! empty($service['deploy']['resources']['limits'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Included resources cover the complete customer stack. Database sidecars reserve
     * 30%; Laravel+Next keeps its established backend/frontend/edge split inside the
     * remainder. These are fairness weights, not ceilings.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, float>
     */
    private function resourceWeights(array $services, string $appServiceName): array
    {
        $names = array_values(array_map('strval', array_keys($services)));
        $databaseNames = array_values(array_filter($names, fn (string $name) => in_array(
            strtolower($name),
            ['mysql', 'mariadb', 'postgres', 'postgresql', 'mongodb', 'mongo', 'db'],
            true
        )));
        $weights = array_fill_keys($names, 0.0);
        $databaseShare = $databaseNames === [] ? 0.0 : 0.30;
        foreach ($databaseNames as $name) {
            $weights[$name] = $databaseShare / count($databaseNames);
        }

        $applicationShare = 1 - $databaseShare;
        $hasNextStack = in_array(LaravelNextGatewayProxy::BACKEND_SERVICE, $names, true)
            && in_array(LaravelNextGatewayProxy::FRONTEND_SERVICE, $names, true);
        if ($hasNextStack) {
            $weights[LaravelNextGatewayProxy::BACKEND_SERVICE] = $applicationShare * 0.55;
            $weights[LaravelNextGatewayProxy::FRONTEND_SERVICE] = $applicationShare * 0.40;
            if (in_array(LaravelNextGatewayProxy::EDGE_SERVICE, $names, true)) {
                $weights[LaravelNextGatewayProxy::EDGE_SERVICE] = $applicationShare * 0.05;
            }
        } else {
            $applicationNames = array_values(array_diff($names, $databaseNames));
            $primary = in_array($appServiceName, $applicationNames, true)
                ? $appServiceName
                : ($applicationNames[0] ?? null);
            $otherNames = array_values(array_diff($applicationNames, [$primary]));
            $otherShare = $otherNames === [] ? 0.0 : min(0.20, 0.05 * count($otherNames));
            if ($primary !== null) {
                $weights[$primary] = $applicationShare - $otherShare;
            }
            foreach ($otherNames as $name) {
                $weights[$name] = $otherShare / count($otherNames);
            }
        }

        $sum = array_sum($weights);

        return array_map(fn (float $value) => $value / max(0.01, $sum), $weights);
    }
}
