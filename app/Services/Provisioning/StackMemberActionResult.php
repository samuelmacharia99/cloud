<?php

namespace App\Services\Provisioning;

final class StackMemberActionResult
{
    public function __construct(
        public readonly StackMember $member,
        public readonly bool $running,
        public readonly int $waitedSeconds,
        public readonly StackMemberState $state,
    ) {}
}
