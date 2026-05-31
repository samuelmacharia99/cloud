<?php

namespace App\Services\Provisioning;

final class ContainerDeployResult
{
    public function __construct(
        public readonly bool $databaseReset = false,
        public readonly ?string $laravelDatabaseSyncMessage = null,
    ) {}
}
