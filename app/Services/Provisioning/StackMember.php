<?php

namespace App\Services\Provisioning;

use App\Enums\StackMemberKind;

/**
 * One container of a customer's stack as the project page shows it: which
 * compose service it is, what it is for, what it is called on the node, and
 * the last observed state. Synthesized members describe a stack that has no
 * compose file yet (or one that could not be read) and carry no actions.
 */
final class StackMember
{
    public function __construct(
        public readonly string $composeKey,
        public readonly StackMemberKind $kind,
        public readonly string $label,
        public readonly string $containerName,
        public readonly int $serviceId,
        public readonly ?string $databaseType,
        public readonly StackMemberState $state,
        public readonly bool $synthesized = false,
    ) {}

    public function withState(StackMemberState $state): self
    {
        return new self(
            $this->composeKey,
            $this->kind,
            $this->label,
            $this->containerName,
            $this->serviceId,
            $this->databaseType,
            $state,
            $this->synthesized,
        );
    }

    public function id(): string
    {
        return $this->serviceId.':'.$this->composeKey;
    }

    public function canRestartAlone(): bool
    {
        return $this->kind->isIndividuallyRestartable() && ! $this->synthesized;
    }

    public function databaseTypeLabel(): ?string
    {
        return match ($this->databaseType) {
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'postgresql' => 'PostgreSQL',
            'mongodb' => 'MongoDB',
            'redis' => 'Redis',
            null => null,
            default => ucfirst($this->databaseType),
        };
    }
}
