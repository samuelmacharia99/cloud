<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use Carbon\CarbonImmutable;

/**
 * The cached per-container states of one deployment, as written to
 * container_deployments.member_states by StackMemberStateService.
 *
 * Shape (version 1):
 *   {version, checked_at, reachable, error, error_at,
 *    containers: {"<container name>": {state, status, checked_at, service}}}
 */
final class StackMemberSnapshot
{
    public const VERSION = 1;

    /**
     * @param  array<string, array{state: StackMemberState, service: ?string}>  $containers  keyed by container name
     */
    public function __construct(
        public readonly array $containers,
        public readonly ?CarbonImmutable $checkedAt,
        public readonly bool $reachable,
        public readonly ?string $error,
        public readonly ?CarbonImmutable $errorAt,
    ) {}

    public static function empty(): self
    {
        return new self([], null, false, null, null);
    }

    public static function fromDeployment(ContainerDeployment $deployment): self
    {
        $raw = $deployment->member_states;
        if (! is_array($raw)) {
            return self::empty();
        }

        $containers = [];
        foreach ((array) ($raw['containers'] ?? []) as $name => $row) {
            if (! is_array($row) || ! is_string($name) || $name === '') {
                continue;
            }
            $containers[$name] = [
                'state' => StackMemberState::fromArray($row),
                'service' => isset($row['service']) ? (string) $row['service'] : null,
            ];
        }

        return new self(
            $containers,
            self::time($raw['checked_at'] ?? null) ?? ($deployment->member_states_checked_at
                ? CarbonImmutable::instance($deployment->member_states_checked_at)
                : null),
            (bool) ($raw['reachable'] ?? false),
            isset($raw['error']) ? (string) $raw['error'] : null,
            self::time($raw['error_at'] ?? null),
        );
    }

    public function hasBeenChecked(): bool
    {
        return $this->checkedAt !== null;
    }

    /**
     * State for one member. Matched by container name first, then by the
     * compose service label the probe recorded, so a member without an
     * explicit container_name still resolves regardless of naming scheme.
     */
    public function stateFor(string $containerName, ?string $composeKey, bool $stale, bool $unknown): StackMemberState
    {
        if (! $this->hasBeenChecked() || $unknown) {
            return StackMemberState::unknown($this->checkedAt);
        }

        $row = $this->containers[$containerName] ?? null;
        if ($row === null && $composeKey !== null) {
            foreach ($this->containers as $candidate) {
                if (($candidate['service'] ?? null) === $composeKey) {
                    $row = $candidate;
                    break;
                }
            }
        }

        if ($row === null) {
            return $this->reachable
                ? StackMemberState::missing($this->checkedAt, $stale)
                : StackMemberState::unknown($this->checkedAt);
        }

        return $row['state']->withStale($stale);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $containers = [];
        foreach ($this->containers as $name => $row) {
            $containers[$name] = $row['state']->toArray() + ['service' => $row['service']];
        }

        return [
            'version' => self::VERSION,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'reachable' => $this->reachable,
            'error' => $this->error,
            'error_at' => $this->errorAt?->toIso8601String(),
            'containers' => $containers,
        ];
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
