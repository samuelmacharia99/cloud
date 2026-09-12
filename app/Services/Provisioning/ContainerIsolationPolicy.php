<?php

namespace App\Services\Provisioning;

use Symfony\Component\Yaml\Yaml;

/**
 * The tenant boundary of a rendered stack, applied as the last step of
 * renderCompose and re-applied to running stacks by containers:apply-isolation.
 *
 * Three things a customer stack must never do on a shared host: publish a
 * port on the node's public interface (nginx on the host is the only front
 * door, and it proxies to loopback), share a Docker network with another
 * customer's stack (the old talksasa-net bridge let any container resolve any
 * other tenant's {app}-db by name), and run with capabilities a web workload
 * has no use for. This class owns all three so nothing else in the renderer
 * has to remember them. Pure by design: no SSH, no database, no config()
 * lookups, because container-free unit tests render compose too.
 *
 * Every service in the stack is covered, including template-declared sidecars
 * and the edge gateways, and the marker plus semantic checks in isCurrent()
 * mean a hand-edited file is caught, not just an unstamped one.
 */
class ContainerIsolationPolicy
{
    public const POLICY_VERSION = 'isolation-v1';

    public const MARKER_KEY = 'x-talksasa-isolation-policy';

    /**
     * The one bridge every container host has. Stacks whose template opts in
     * attach their app service to it as a second network so same-node links
     * (Hermes to Ollama) keep resolving by container name. Nothing else joins.
     */
    public const SHARED_NETWORK_NAME = 'talksasa-net';

    public const SHARED_NETWORK_KEY = 'shared';

    public const NO_NEW_PRIVILEGES = 'no-new-privileges:true';

    private ContainerIsolationOptions $options;

    public function __construct(?ContainerIsolationOptions $options = null)
    {
        $this->options = $options ?? new ContainerIsolationOptions;
    }

    public function options(): ContainerIsolationOptions
    {
        return $this->options;
    }

    /**
     * Explicit so the verifier and node rescans never have to guess what
     * Compose would have derived from the project directory.
     */
    public static function stackNetworkName(string $containerName): string
    {
        $name = strtolower(trim($containerName));
        if ($name === '' || ! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name)) {
            throw new \InvalidArgumentException("Container name is not safe for a Docker network: {$containerName}");
        }

        return $name.'-net';
    }

    /**
     * The compose service that is the application. Gateway stacks key it
     * `backend` and give the published port to `edge`; every other stack keys
     * it by container name. The rollout and live migration re-apply the
     * policy to files they did not render, so they need the same answer the
     * renderer had.
     */
    public static function appServiceKey(string $yaml, string $containerName): string
    {
        return str_contains($yaml, "\n  ".LaravelNextGatewayProxy::BACKEND_SERVICE.":\n")
            ? LaravelNextGatewayProxy::BACKEND_SERVICE
            : $containerName;
    }

    /**
     * @param  array<string, mixed>  $compose
     */
    public function apply(
        array &$compose,
        string $appServiceName,
        string $containerName,
        ?string $subnet = null,
        ?string $templateSlug = null,
    ): void {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($services === []) {
            return;
        }

        $network = self::stackNetworkName($containerName);
        $joinsShared = $this->options->joinsSharedNetwork($templateSlug);
        $caps = $this->options->forSlug($templateSlug);

        foreach ($services as $name => &$service) {
            if (! is_array($service)) {
                continue;
            }

            $this->bindPublishedPorts($service);
            $this->harden($service, $caps);
            $this->normaliseServiceNetworks($service);

            if ($joinsShared && (string) $name === $appServiceName) {
                $service['networks'][self::SHARED_NETWORK_KEY] = [];
            } elseif (isset($service['networks'][self::SHARED_NETWORK_KEY])) {
                unset($service['networks'][self::SHARED_NETWORK_KEY]);
            }

            if (isset($service['networks']) && $service['networks'] === []) {
                unset($service['networks']);
            }
        }
        unset($service);

        $compose['services'] = $services;

        $networks = is_array($compose['networks'] ?? null) ? $compose['networks'] : [];
        $default = ['name' => $network, 'driver' => 'bridge'];
        if ($subnet !== null && $subnet !== '') {
            $default['ipam'] = ['config' => [['subnet' => $subnet]]];
        }
        $networks['default'] = $default;
        if ($joinsShared) {
            $networks[self::SHARED_NETWORK_KEY] = ['name' => self::SHARED_NETWORK_NAME, 'external' => true];
        } else {
            unset($networks[self::SHARED_NETWORK_KEY]);
        }
        $compose['networks'] = $networks;

        $compose[self::MARKER_KEY] = [
            'version' => self::POLICY_VERSION,
            'network' => $network,
            'subnet' => $subnet,
            'publish_bind' => $this->options->publishBindAddress,
            'shared_network' => $joinsShared,
        ];
    }

    public function applyToYaml(
        string $yaml,
        string $appServiceName,
        string $containerName,
        ?string $subnet = null,
        ?string $templateSlug = null,
    ): string {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose)) {
            throw new \RuntimeException('Existing Docker Compose configuration is invalid.');
        }

        $this->apply($compose, $appServiceName, $containerName, $subnet, $templateSlug);

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * Marker and substance both. A file stamped by an older run, or one
     * somebody edited by hand, must read as stale so the rollout repairs it.
     */
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

        if (($compose[self::MARKER_KEY]['version'] ?? null) !== self::POLICY_VERSION) {
            return false;
        }

        $default = $compose['networks']['default'] ?? null;
        if (! is_array($default) || ! empty($default['external']) || ! str_ends_with((string) ($default['name'] ?? ''), '-net')) {
            return false;
        }

        foreach ($compose['services'] as $service) {
            if (! is_array($service)) {
                continue;
            }

            foreach ((array) ($service['ports'] ?? []) as $entry) {
                if (is_string($entry) && ! $this->isBound($entry)) {
                    return false;
                }
                if (is_array($entry) && empty($entry['host_ip'])) {
                    return false;
                }
            }

            if ($this->options->noNewPrivileges
                && ! in_array(self::NO_NEW_PRIVILEGES, (array) ($service['security_opt'] ?? []), true)) {
                return false;
            }
        }

        return true;
    }

    public function subnetFromYaml(string $yaml): ?string
    {
        try {
            $compose = Yaml::parse($yaml);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($compose)) {
            return null;
        }

        $subnet = $compose[self::MARKER_KEY]['subnet']
            ?? $compose['networks']['default']['ipam']['config'][0]['subnet']
            ?? null;

        return is_string($subnet) && $subnet !== '' ? $subnet : null;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function bindPublishedPorts(array &$service): void
    {
        if (! isset($service['ports']) || ! is_array($service['ports'])) {
            return;
        }

        $bind = $this->options->publishBindAddress;
        foreach ($service['ports'] as &$entry) {
            if (is_string($entry) || is_int($entry)) {
                $entry = (string) $entry;
                if ($this->isBound($entry)) {
                    continue;
                }
                // "HOST:CONTAINER[/proto]" gains a bind address; a bare
                // "CONTAINER[/proto]" keeps its random host port but on
                // loopback only, which is what the empty middle field means.
                $entry = str_contains($entry, ':') ? "{$bind}:{$entry}" : "{$bind}::{$entry}";

                continue;
            }

            if (is_array($entry) && empty($entry['host_ip'])) {
                $entry['host_ip'] = $bind;
            }
        }
        unset($entry);
    }

    private function isBound(string $entry): bool
    {
        if (str_starts_with($entry, '[')) {
            return true;
        }

        $head = explode(':', $entry, 2)[0];

        return str_contains($head, '.');
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array{cap_add: list<string>, cap_drop: list<string>, pids_limit: int}  $caps
     */
    private function harden(array &$service, array $caps): void
    {
        if ($this->options->noNewPrivileges) {
            $opts = array_values(array_filter((array) ($service['security_opt'] ?? []), 'is_string'));
            if (! in_array(self::NO_NEW_PRIVILEGES, $opts, true)) {
                $opts[] = self::NO_NEW_PRIVILEGES;
            }
            $service['security_opt'] = $opts;
        }

        $existingDrop = array_map('strtoupper', array_filter((array) ($service['cap_drop'] ?? []), 'is_string'));
        if (! in_array('ALL', $existingDrop, true)) {
            $drop = array_values(array_unique(array_merge($existingDrop, $caps['cap_drop'])));
            if ($drop !== []) {
                $service['cap_drop'] = $drop;
            }
        }

        $existingAdd = array_map('strtoupper', array_filter((array) ($service['cap_add'] ?? []), 'is_string'));
        $add = array_values(array_unique(array_merge($existingAdd, $caps['cap_add'])));
        if ($add !== []) {
            $service['cap_add'] = $add;
        }

        if (! isset($service['pids_limit']) && $caps['pids_limit'] > 0) {
            $service['pids_limit'] = $caps['pids_limit'];
        }
    }

    /**
     * Compose accepts `networks:` as a list of names or a map of name to
     * options. Aliases only live in the map form, and so does the shared
     * network attachment, so a list is promoted before anything is added.
     *
     * @param  array<string, mixed>  $service
     */
    private function normaliseServiceNetworks(array &$service): void
    {
        if (! isset($service['networks'])) {
            return;
        }

        $networks = $service['networks'];
        if (is_array($networks) && array_is_list($networks)) {
            $mapped = [];
            foreach ($networks as $name) {
                if (is_string($name) && $name !== '') {
                    $mapped[$name] = [];
                }
            }
            $networks = $mapped;
        }

        $service['networks'] = is_array($networks) ? $networks : [];
    }
}
