<?php

namespace App\Services\Provisioning;

/**
 * One migration command, resolved from what the repository actually contains.
 *
 * `source` is what the customer is shown so the platform never looks like it
 * guessed: the file or the package.json entry the command was read from.
 */
class ContainerMigrationPlan
{
    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public readonly string $tool,
        public readonly string $command,
        public readonly string $workDir,
        public readonly string $source,
        public readonly array $environment = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'command' => $this->command,
            'work_dir' => $this->workDir,
            'source' => $this->source,
        ];
    }
}
