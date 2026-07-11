<?php

namespace App\Services\Provisioning;

final class ContainerDeployOptions
{
    public function __construct(
        public readonly bool $isRedeploy = false,
        public readonly bool $resetDatabase = false,
        public readonly bool $quiet = false,
    ) {}

    public static function redeploy(bool $resetDatabase = false): self
    {
        return new self(isRedeploy: true, resetDatabase: $resetDatabase);
    }

    /**
     * Deploy without customer notifications (admin silent convert / ops).
     */
    public static function quiet(): self
    {
        return new self(quiet: true);
    }

    public function shouldResetDatabase(bool $hasDatabaseSidecar): bool
    {
        return $this->isRedeploy && $this->resetDatabase && $hasDatabaseSidecar;
    }

    public function shouldPrepareLaravelApplication(string $templateSlug): bool
    {
        return $templateSlug === 'laravel';
    }

    public function shouldSyncLaravelDatabase(string $templateSlug): bool
    {
        return $this->shouldRunLaravelMigrations($templateSlug);
    }

    public function shouldRunLaravelMigrations(string $templateSlug): bool
    {
        if ($templateSlug !== 'laravel') {
            return false;
        }

        return ! $this->isRedeploy || ($this->isRedeploy && $this->resetDatabase);
    }
}
