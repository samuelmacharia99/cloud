<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Which Docker network a stack is actually on, asked of the node.
 *
 * During the rollout a host carries both kinds of stack: ones still on the
 * shared bridge and ones on their own network. Code that connects a sidecar
 * by hand or runs a throwaway build container next to the app has to join
 * the right one, and the node is the only authority on which that is. One
 * `docker network inspect` answers it.
 */
class ContainerStackNetworkLocator
{
    public function forContainer(SSHService $ssh, string $containerName): string
    {
        try {
            $stackNetwork = ContainerIsolationPolicy::stackNetworkName($containerName);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Container name cannot map to a stack network; using the shared bridge', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);

            return ContainerIsolationPolicy::SHARED_NETWORK_NAME;
        }

        $answer = trim((string) $ssh->exec(
            'docker network inspect '.escapeshellarg($stackNetwork).' >/dev/null 2>&1 && echo yes || echo no',
            15
        ));

        return $answer === 'yes' ? $stackNetwork : ContainerIsolationPolicy::SHARED_NETWORK_NAME;
    }

    /**
     * Build helpers only know the host path they mount as /app. The stack
     * directory sits directly under the container base path, so the name is
     * the first segment after it.
     */
    public function forHostAppPath(SSHService $ssh, string $hostAppPath): string
    {
        $base = rtrim(ContainerDeploymentService::CONTAINER_BASE_PATH, '/').'/';
        $path = rtrim($hostAppPath, '/').'/';

        if (! str_starts_with($path, $base)) {
            return ContainerIsolationPolicy::SHARED_NETWORK_NAME;
        }

        $containerName = explode('/', substr($path, strlen($base)), 2)[0];
        if ($containerName === '') {
            return ContainerIsolationPolicy::SHARED_NETWORK_NAME;
        }

        return $this->forContainer($ssh, $containerName);
    }
}
