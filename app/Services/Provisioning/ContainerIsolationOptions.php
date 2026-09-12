<?php

namespace App\Services\Provisioning;

/**
 * Everything ContainerIsolationPolicy needs to know, as plain values.
 *
 * The policy runs inside renderCompose, which container-free unit tests call
 * with nothing but a logger bound, so it cannot reach for config() itself.
 * Production builds this from config('containers.isolation') in the service
 * provider; a bare `new ContainerIsolationPolicy` gets these defaults.
 */
final class ContainerIsolationOptions
{
    /**
     * Docker's default capability set minus the ones a customer workload on a
     * shared host has no business holding. NET_RAW lets a container spoof on
     * the bridge, MKNOD and SYS_CHROOT are escape helpers, AUDIT_WRITE and
     * SETFCAP are noise. CHOWN, SETUID, SETGID, DAC_OVERRIDE, FOWNER stay so
     * official images that start as root and drop to a service user keep
     * working.
     */
    public const DEFAULT_CAP_DROP = ['NET_RAW', 'MKNOD', 'AUDIT_WRITE', 'SYS_CHROOT', 'SETFCAP'];

    /**
     * @param  list<string>  $capDrop
     * @param  list<string>  $sharedNetworkSlugs  Template slugs whose app service may reach other stacks by name.
     * @param  array<string, array{cap_add?: list<string>, cap_drop?: list<string>, pids_limit?: int}>  $capOverrides
     */
    public function __construct(
        public readonly string $publishBindAddress = '127.0.0.1',
        public readonly bool $noNewPrivileges = true,
        public readonly int $pidsLimit = 1024,
        public readonly array $capDrop = self::DEFAULT_CAP_DROP,
        public readonly array $sharedNetworkSlugs = ['ollama', 'hermes'],
        public readonly array $capOverrides = [],
    ) {
        if (filter_var($this->publishBindAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("Publish bind address is not an IP: {$this->publishBindAddress}");
        }
        if ($this->pidsLimit < 0) {
            throw new \InvalidArgumentException('pids_limit cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $config  The containers.isolation block.
     */
    public static function fromArray(array $config): self
    {
        $strings = static fn (mixed $value): array => array_values(array_filter(array_map(
            static fn (mixed $item): string => strtoupper(trim((string) $item)),
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));

        $overrides = [];
        foreach ((array) ($config['cap_overrides'] ?? []) as $slug => $override) {
            if (! is_string($slug) || ! is_array($override)) {
                continue;
            }
            $entry = [];
            if (array_key_exists('cap_add', $override)) {
                $entry['cap_add'] = $strings($override['cap_add']);
            }
            if (array_key_exists('cap_drop', $override)) {
                $entry['cap_drop'] = $strings($override['cap_drop']);
            }
            if (isset($override['pids_limit'])) {
                $entry['pids_limit'] = max(0, (int) $override['pids_limit']);
            }
            $overrides[strtolower(trim($slug))] = $entry;
        }

        return new self(
            publishBindAddress: trim((string) ($config['publish_bind_address'] ?? '127.0.0.1')) ?: '127.0.0.1',
            noNewPrivileges: (bool) ($config['no_new_privileges'] ?? true),
            pidsLimit: (int) ($config['pids_limit'] ?? 1024),
            capDrop: array_key_exists('cap_drop', $config) ? $strings($config['cap_drop']) : self::DEFAULT_CAP_DROP,
            sharedNetworkSlugs: array_values(array_map(
                static fn (mixed $slug): string => strtolower(trim((string) $slug)),
                is_array($config['shared_network_slugs'] ?? null) ? $config['shared_network_slugs'] : ['ollama', 'hermes'],
            )),
            capOverrides: $overrides,
        );
    }

    /**
     * @return array{cap_add: list<string>, cap_drop: list<string>, pids_limit: int}
     */
    public function forSlug(?string $slug): array
    {
        $override = $slug !== null ? ($this->capOverrides[strtolower(trim($slug))] ?? []) : [];
        $add = $override['cap_add'] ?? [];

        return [
            'cap_add' => $add,
            // A capability a slug explicitly adds back is not also dropped.
            'cap_drop' => array_values(array_diff(array_unique(array_merge($this->capDrop, $override['cap_drop'] ?? [])), $add)),
            'pids_limit' => $override['pids_limit'] ?? $this->pidsLimit,
        ];
    }

    public function joinsSharedNetwork(?string $slug): bool
    {
        return $slug !== null && in_array(strtolower(trim($slug)), $this->sharedNetworkSlugs, true);
    }
}
