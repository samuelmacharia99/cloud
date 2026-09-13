<?php

namespace App\Services\Customer;

use App\Models\ContainerTemplate;

final class StackChoice
{
    public function __construct(
        public readonly ContainerTemplate $template,
        public readonly bool $eligible,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array{eligible: bool, reason: ?string}
     */
    public function toArray(): array
    {
        return ['eligible' => $this->eligible, 'reason' => $this->reason];
    }
}
