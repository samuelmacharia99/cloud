<?php

namespace App\Services\Provisioning;

class ApplicationRuntime
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public readonly array $command,
        public readonly string $source,
        public readonly string $label,
    ) {}
}
