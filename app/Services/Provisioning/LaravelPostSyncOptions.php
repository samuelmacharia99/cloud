<?php

namespace App\Services\Provisioning;

final class LaravelPostSyncOptions
{
    public function __construct(
        public readonly bool $refreshRuntime = false,
        public readonly bool $configureEnvironment = true,
        public readonly bool $runComposer = true,
        public readonly bool $runMigrations = false,
        public readonly bool $finalizeApplication = true,
        public readonly bool $normalizePermissions = true,
        public readonly bool $waitForDatabase = true,
        public readonly bool $requireHttpHealth = false,
    ) {}
}
