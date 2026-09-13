<?php

namespace App\Services\Customer;

use App\Models\CustomerProject;
use App\Models\Service;

/**
 * An existing plan the customer may deploy a new service on: the project,
 * its billing anchor, how much of the plan is still unallocated, and
 * whether it has room for one more included service.
 */
final class DeployTarget
{
    public function __construct(
        public readonly CustomerProject $project,
        public readonly Service $anchor,
        public readonly string $planName,
        public readonly int $serviceCount,
        public readonly float $remainingCpuShare,
        public readonly float $remainingMemoryShare,
        public readonly bool $hasRoom,
        public readonly ?string $fullReason,
        public readonly ?int $pinnedTemplateId,
        public readonly array $limits,
        public readonly int $eligibleStackCount,
    ) {}

    public function remainingCpuPercent(): string
    {
        return self::percent($this->remainingCpuShare);
    }

    public function remainingMemoryPercent(): string
    {
        return self::percent($this->remainingMemoryShare);
    }

    private static function percent(float $share): string
    {
        return rtrim(rtrim(number_format(max(0.0, $share) * 100, 1), '0'), '.');
    }
}
