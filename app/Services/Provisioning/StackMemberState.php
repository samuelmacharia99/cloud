<?php

namespace App\Services\Provisioning;

use Carbon\CarbonImmutable;

/**
 * The observed state of one container in a stack, normalised from Docker's
 * own vocabulary and stamped with when it was observed. A state that is too
 * old to trust is reported as stale and, past a second threshold, as unknown:
 * the page must never show a confident "Running" from an hour-old sample.
 */
final class StackMemberState
{
    public const RUNNING = 'running';

    public const RESTARTING = 'restarting';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';

    public const MISSING = 'missing';

    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $state,
        public readonly ?string $status,
        public readonly ?CarbonImmutable $checkedAt,
        public readonly bool $stale = false,
    ) {}

    public static function fromDocker(string $dockerState, ?string $dockerStatus, CarbonImmutable $checkedAt): self
    {
        $state = match (strtolower(trim($dockerState))) {
            'running' => self::RUNNING,
            'restarting' => self::RESTARTING,
            'paused' => self::PAUSED,
            'created', 'exited', 'dead', 'removing' => self::STOPPED,
            '' => self::UNKNOWN,
            default => self::STOPPED,
        };

        $status = trim((string) $dockerStatus);

        return new self($state, $status !== '' ? $status : null, $checkedAt);
    }

    public static function pending(): self
    {
        return new self(self::PENDING, null, null);
    }

    public static function unknown(?CarbonImmutable $lastSeen = null): self
    {
        return new self(self::UNKNOWN, null, $lastSeen, $lastSeen !== null);
    }

    public static function missing(CarbonImmutable $checkedAt, bool $stale = false): self
    {
        return new self(self::MISSING, null, $checkedAt, $stale);
    }

    public function withStale(bool $stale): self
    {
        return new self($this->state, $this->status, $this->checkedAt, $stale);
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    public function isKnown(): bool
    {
        return ! in_array($this->state, [self::PENDING, self::UNKNOWN], true);
    }

    /**
     * Docker appends "(healthy)" / "(unhealthy)" / "(health: starting)" to the
     * status text when the container has a healthcheck; null means it has none.
     */
    public function isHealthy(): ?bool
    {
        $status = strtolower((string) $this->status);
        if (str_contains($status, '(healthy)')) {
            return true;
        }
        if (str_contains($status, '(unhealthy)')) {
            return false;
        }

        return null;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::RUNNING => 'Running',
            self::RESTARTING => 'Restarting',
            self::PAUSED => 'Paused',
            self::STOPPED => 'Stopped',
            self::MISSING => 'Not created',
            self::PENDING => 'Pending',
            default => 'Unknown',
        };
    }

    /**
     * @return array{state: string, status: ?string, checked_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'status' => $this->status,
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $checkedAt = isset($row['checked_at']) && is_string($row['checked_at']) && $row['checked_at'] !== ''
            ? CarbonImmutable::parse($row['checked_at'])
            : null;

        return new self(
            (string) ($row['state'] ?? self::UNKNOWN),
            isset($row['status']) ? (string) $row['status'] : null,
            $checkedAt,
        );
    }
}
