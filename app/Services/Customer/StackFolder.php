<?php

namespace App\Services\Customer;

use App\Enums\StackMemberKind;
use App\Models\Service;
use App\Services\Provisioning\StackMember;
use App\Services\Provisioning\StackMemberState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One deployed stack as the project page shows it: the billing anchor, the
 * role services split off it (API + Web), and every container they run,
 * database included.
 */
final class StackFolder
{
    public const RUNNING = 'running';

    public const DEGRADED = 'degraded';

    public const STOPPED = 'stopped';

    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    /**
     * @param  Collection<int, Service>  $services  anchor first
     * @param  list<StackMember>  $members
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly Service $anchor,
        public readonly Collection $services,
        public readonly array $members,
        public readonly string $aggregateState,
        public readonly ?CarbonImmutable $checkedAt,
        public readonly bool $stale,
        public readonly ?string $lastError = null,
    ) {}

    public function memberCount(): int
    {
        return count($this->members);
    }

    public function runningCount(): int
    {
        return count(array_filter($this->members, fn (StackMember $member) => $member->state->isRunning()));
    }

    public function notRunningCount(): int
    {
        return count(array_filter(
            $this->members,
            fn (StackMember $member) => $member->state->isKnown() && ! $member->state->isRunning()
        ));
    }

    /**
     * @return list<StackMember>
     */
    public function databaseMembers(): array
    {
        return array_values(array_filter($this->members, fn (StackMember $member) => $member->kind === StackMemberKind::Database));
    }

    /**
     * @return list<StackMember>
     */
    public function membersOf(Service $service): array
    {
        return array_values(array_filter($this->members, fn (StackMember $member) => $member->serviceId === (int) $service->id));
    }

    public function isDeployed(): bool
    {
        return $this->anchor->containerDeployment !== null
            && (string) $this->anchor->containerDeployment->status !== 'terminated';
    }

    public function isSynthesized(): bool
    {
        return $this->members !== [] && collect($this->members)->every(fn (StackMember $member) => $member->synthesized);
    }

    public function isSplitAcrossServices(): bool
    {
        return $this->services->count() > 1;
    }

    public function aggregateLabel(): string
    {
        return match ($this->aggregateState) {
            self::RUNNING => 'All running',
            self::DEGRADED => $this->notRunningCount().' of '.$this->memberCount().' not running',
            self::STOPPED => 'Stopped',
            self::PENDING => 'Pending',
            default => 'State unknown',
        };
    }

    /**
     * @param  list<StackMember>  $members
     */
    public static function aggregateOf(array $members): string
    {
        if ($members === []) {
            return self::PENDING;
        }

        $states = array_map(fn (StackMember $member) => $member->state->state, $members);
        $pending = array_filter($states, fn (string $state) => $state === StackMemberState::PENDING);
        if (count($pending) === count($states)) {
            return self::PENDING;
        }

        $known = array_filter($states, fn (string $state) => ! in_array($state, [StackMemberState::PENDING, StackMemberState::UNKNOWN], true));
        if ($known === []) {
            return self::UNKNOWN;
        }

        $running = count(array_filter($known, fn (string $state) => $state === StackMemberState::RUNNING));
        if ($running === count($members)) {
            return self::RUNNING;
        }
        if ($running === 0) {
            return self::STOPPED;
        }

        return self::DEGRADED;
    }
}
