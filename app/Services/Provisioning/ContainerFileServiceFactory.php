<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

/**
 * Builds a ContainerFileService on an SSH session to the deployment's node.
 * Resolved from the container so tests can substitute a mocked session.
 */
class ContainerFileServiceFactory
{
    public function make(ContainerDeployment $deployment): ContainerFileService
    {
        $node = $deployment->node;
        if (! $node) {
            throw new \RuntimeException('Deployment has no node.');
        }

        return new ContainerFileService(SSHService::forNode($node));
    }
}
