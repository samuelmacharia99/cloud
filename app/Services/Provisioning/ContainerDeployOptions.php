<?php

namespace App\Services\Provisioning;

final class ContainerDeployOptions
{
    public function __construct(
        public readonly bool $isRedeploy = false,
        public readonly bool $resetDatabase = false,
    ) {}

    public static function redeploy(bool $resetDatabase = false): self
    {
        return new self(isRedeploy: true, resetDatabase: $resetDatabase);
    }

    public function shouldResetDatabase(bool $hasDatabaseSidecar): bool
    {
        return $this->isRedeploy && $this->resetDatabase && $hasDatabaseSidecar;
    }

    public function shouldSyncLaravelDatabase(string $templateSlug): bool
    {
        return $this->isRedeploy
            && $this->resetDatabase
            && $templateSlug === 'laravel';
    }
}
