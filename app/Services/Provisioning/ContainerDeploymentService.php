<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Production-grade container deployment service
 * Handles Docker Compose lifecycle management via SSH
 */
class ContainerDeploymentService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';
    private const PORT_RANGE_START = 30000;
    private const PORT_RANGE_END = 40000;
    private const DEPLOY_TIMEOUT = 120;
    private const HEALTH_CHECK_RETRIES = 3;
    private const HEALTH_CHECK_DELAY = 5;

    /**
     * Deploy a service as a Docker Compose container
     */
    public function deploy(Service $service): void
    {
        try {
            // Load relationships
            $service->load('product.containerTemplate', 'user', 'node');

            // Validation
            if (! $service->product || ! $service->product->containerTemplate) {
                throw new \DomainException('Service must have a container template');
            }

            $template = $service->product->containerTemplate;

            // Select node if not already set
            if (! $service->node_id) {
                $node = $this->selectNode();
                $service->update(['node_id' => $node->id]);
            } else {
                $node = $service->node;
            }

            if (! $node || $node->type !== 'container_host' || ! $node->is_active) {
                throw new \DomainException('No active container host node available');
            }

            // Generate container name
            $containerName = 'talksasa-' . $service->id . '-' . strtolower(Str::random(6));

            // Assign port
            $port = $this->assignPort($node);

            // Collect environment variables
            $envValues = $service->service_meta['env_values'] ?? [];
            $envVars = $this->buildEnvironmentVariables($template, $envValues, $service);

            // Render docker-compose.yml
            $composeYaml = $this->renderCompose($template, $containerName, $port, $envVars);

            // Create deployment record
            $deployment = ContainerDeployment::create([
                'service_id' => $service->id,
                'node_id' => $node->id,
                'container_name' => $containerName,
                'status' => 'deploying',
                'docker_compose_content' => $composeYaml,
                'assigned_port' => $port,
                'env_values' => $envVars,
            ]);

            // Update service status
            $service->update(['status' => 'provisioning']);

            // Execute deployment
            $ssh = SSHService::forNode($node);

            try {
                // Create container directory
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $containerName;
                $ssh->mkdirp($containerPath);

                // Upload docker-compose.yml
                $ssh->upload($composeYaml, $containerPath . '/docker-compose.yml');

                // Deploy container
                $ssh->exec(
                    "cd {$containerPath} && docker compose up -d",
                    self::DEPLOY_TIMEOUT
                );

                // Wait for container to start and health check
                $this->waitForContainerHealth($ssh, $containerName);

                // Get container status
                $status = $this->getContainerStatus($ssh, $containerName);
                $internalIp = $status['internal_ip'] ?? null;

                // Update deployment status
                $deployment->update([
                    'status' => 'running',
                    'deployed_at' => now(),
                    'internal_ip' => $internalIp,
                    'last_status_check_at' => now(),
                    'last_status_check_output' => json_encode($status),
                ]);

                // Update service and store credentials
                $credentials = $this->generateCredentials($service, $deployment, $envVars);
                $service->update([
                    'status' => 'active',
                    'credentials' => json_encode($credentials),
                ]);

                // Notify user
                app(NotificationService::class)->notifyServiceActivated($service->fresh());

                \Log::info("Container deployment successful for service {$service->id}", [
                    'container' => $containerName,
                    'node' => $node->id,
                    'port' => $port,
                ]);
            } catch (SSHCommandException | SSHConnectionException $e) {
                $deployment->update([
                    'status' => 'failed',
                    'last_status_check_output' => $e->getMessage(),
                ]);

                $service->update(['status' => 'failed']);

                \Log::error("Container deployment failed for service {$service->id}: " . $e->getMessage(), [
                    'container' => $containerName,
                    'exception' => $e,
                ]);

                throw new \RuntimeException("Container deployment failed: " . $e->getMessage(), 0, $e);
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Container provisioning error for service {$service->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Suspend (stop) a running container
     */
    public function suspend(Service $service): void
    {
        try {
            $deployment = $service->containerDeployment;

            if (! $deployment || ! $deployment->node) {
                throw new \DomainException('Container deployment not found');
            }

            $ssh = SSHService::forNode($deployment->node);

            try {
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose stop", self::DEPLOY_TIMEOUT);

                $deployment->update([
                    'status' => 'stopped',
                    'last_status_check_at' => now(),
                ]);

                $service->update(['status' => 'suspended']);

                \Log::info("Container suspended for service {$service->id}");
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to suspend container for service {$service->id}: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Resume (start) a stopped container
     */
    public function unsuspend(Service $service): void
    {
        try {
            $deployment = $service->containerDeployment;

            if (! $deployment || ! $deployment->node) {
                throw new \DomainException('Container deployment not found');
            }

            $ssh = SSHService::forNode($deployment->node);

            try {
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose start", self::DEPLOY_TIMEOUT);

                $deployment->update([
                    'status' => 'running',
                    'last_status_check_at' => now(),
                ]);

                $service->update(['status' => 'active']);

                \Log::info("Container resumed for service {$service->id}");
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to resume container for service {$service->id}: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Terminate and completely remove a container
     */
    public function terminate(Service $service): void
    {
        try {
            $deployment = $service->containerDeployment;

            if ($deployment && $deployment->node) {
                $ssh = SSHService::forNode($deployment->node);

                try {
                    $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;

                    // Stop and remove containers
                    @$ssh->exec("cd {$containerPath} && docker compose down -v", self::DEPLOY_TIMEOUT);

                    // Remove directory
                    @$ssh->deleteDir($containerPath);

                    $deployment->update([
                        'status' => 'terminated',
                        'terminated_at' => now(),
                    ]);

                    \Log::info("Container terminated for service {$service->id}");
                } finally {
                    $ssh->disconnect();
                }
            }

            $service->update([
                'status' => 'terminated',
                'terminate_date' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to terminate container for service {$service->id}: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Restart a running container
     */
    public function restart(Service $service): void
    {
        try {
            $deployment = $service->containerDeployment;

            if (! $deployment || ! $deployment->node) {
                throw new \DomainException('Container deployment not found');
            }

            $ssh = SSHService::forNode($deployment->node);

            try {
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose restart", self::DEPLOY_TIMEOUT);

                $deployment->update(['last_status_check_at' => now()]);

                \Log::info("Container restarted for service {$service->id}");
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Get container logs
     */
    public function getLogs(Service $service, int $lines = 100): string
    {
        try {
            $deployment = $service->containerDeployment;

            if (! $deployment || ! $deployment->node) {
                return '';
            }

            $ssh = SSHService::forNode($deployment->node);

            try {
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $output = $ssh->exec(
                    "cd {$containerPath} && docker compose logs --no-color --tail={$lines}",
                    30
                );

                return $output;
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch container logs for service {$service->id}: " . $e->getMessage());

            return "Error fetching logs: " . $e->getMessage();
        }
    }

    /**
     * Get container status
     */
    public function getStatus(Service $service): ?array
    {
        try {
            $deployment = $service->containerDeployment;

            if (! $deployment || ! $deployment->node) {
                return null;
            }

            $ssh = SSHService::forNode($deployment->node);

            try {
                return $this->getContainerStatus($ssh, $deployment->container_name);
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to get container status for service {$service->id}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Select the least-loaded container host node
     */
    private function selectNode(): Node
    {
        $node = Node::where('type', 'container_host')
            ->where('is_active', true)
            ->orderBy('container_count')
            ->first();

        if (! $node) {
            throw new \DomainException('No available container host nodes');
        }

        return $node;
    }

    /**
     * Find and assign an available port
     */
    private function assignPort(Node $node): int
    {
        $usedPorts = ContainerDeployment::where('node_id', $node->id)
            ->whereNotNull('assigned_port')
            ->pluck('assigned_port')
            ->toArray();

        for ($port = self::PORT_RANGE_START; $port <= self::PORT_RANGE_END; $port++) {
            if (! in_array($port, $usedPorts)) {
                return $port;
            }
        }

        throw new \DomainException('No available ports in range ' . self::PORT_RANGE_START . '-' . self::PORT_RANGE_END);
    }

    /**
     * Build complete environment variables including system vars
     */
    private function buildEnvironmentVariables($template, array $userValues, Service $service): array
    {
        $env = [];

        // Add template defaults
        if ($template->environment_variables) {
            foreach ($template->environment_variables as $var) {
                $key = $var['key'];
                $env[$key] = $userValues[$key] ?? $var['default'] ?? '';
            }
        }

        // Add system variables
        $env['APP_PORT'] = (string) $this->assignPort($service->node);
        $env['DATA_DIR'] = '/data';
        $env['COMPOSE_PROJECT_NAME'] = 'talksasa-' . $service->id;

        // Generate secrets if needed
        if (! isset($env['DB_PASSWORD']) || ! $env['DB_PASSWORD']) {
            $env['DB_PASSWORD'] = Str::random(32);
        }
        if (! isset($env['ADMIN_PASSWORD']) || ! $env['ADMIN_PASSWORD']) {
            $env['ADMIN_PASSWORD'] = Str::random(20);
        }

        return $env;
    }

    /**
     * Render docker-compose.yml from template
     */
    private function renderCompose($template, string $containerName, int $port, array $envVars): string
    {
        $compose = [
            'version' => '3.9',
            'services' => [
                $containerName => [
                    'image' => $template->docker_image,
                    'container_name' => $containerName,
                    'restart' => 'unless-stopped',
                    'environment' => $envVars,
                    'ports' => ["{$port}:" . $template->default_port],
                    'deploy' => [
                        'resources' => [
                            'limits' => [
                                'cpus' => (string) $template->required_cpu_cores,
                                'memory' => $template->required_ram_mb . 'M',
                            ],
                        ],
                    ],
                ],
            ],
            'networks' => [
                'default' => [
                    'name' => "talksasa-{$containerName}",
                ],
            ],
        ];

        // Add volumes
        if ($template->volume_paths) {
            $compose['services'][$containerName]['volumes'] = [];
            $compose['volumes'] = [];

            foreach ($template->volume_paths as $volumeName => $mountPath) {
                $compose['services'][$containerName]['volumes'][] = "{$volumeName}:{$mountPath}";
                $compose['volumes'][$volumeName] = null;
            }
        }

        // Add sidecar services
        if ($template->compose_services) {
            foreach ($template->compose_services as $serviceName => $serviceConfig) {
                $compose['services'][$serviceName] = $serviceConfig;
            }
        }

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * Wait for container to be healthy
     */
    private function waitForContainerHealth(SSHService $ssh, string $containerName): void
    {
        for ($attempt = 0; $attempt < self::HEALTH_CHECK_RETRIES; $attempt++) {
            try {
                $status = $this->getContainerStatus($ssh, $containerName);
                if (isset($status['state']) && in_array($status['state'], ['running', 'Up'])) {
                    return;
                }
            } catch (\Exception $e) {
                // Check failed, retry
            }

            if ($attempt < self::HEALTH_CHECK_RETRIES - 1) {
                sleep(self::HEALTH_CHECK_DELAY);
            }
        }

        throw new \RuntimeException("Container failed to reach healthy state after " . (self::HEALTH_CHECK_RETRIES * self::HEALTH_CHECK_DELAY) . " seconds");
    }

    /**
     * Get container status from docker compose ps
     */
    private function getContainerStatus(SSHService $ssh, string $containerName): array
    {
        $containerPath = self::CONTAINER_BASE_PATH . '/' . $containerName;
        $output = $ssh->exec("cd {$containerPath} && docker compose ps --format json 2>/dev/null || echo '[]'", 10);

        $containers = json_decode($output, true) ?? [];

        if (empty($containers)) {
            return [
                'state' => 'unknown',
                'running' => false,
                'internal_ip' => null,
            ];
        }

        $mainContainer = $containers[0];

        return [
            'state' => $mainContainer['State'] ?? 'unknown',
            'running' => stripos($mainContainer['State'] ?? '', 'up') !== false,
            'internal_ip' => $mainContainer['Publisher'] ?? null,
            'full_data' => $mainContainer,
        ];
    }

    /**
     * Generate credentials object for storage
     */
    private function generateCredentials(Service $service, ContainerDeployment $deployment, array $envVars): array
    {
        return [
            'access_url' => $deployment->getAccessUrl(),
            'port' => $deployment->assigned_port,
            'container_name' => $deployment->container_name,
            'admin_username' => $envVars['WORDPRESS_ADMIN_USER'] ?? $envVars['ADMIN_USER'] ?? 'admin',
            'admin_email' => $envVars['WORDPRESS_ADMIN_EMAIL'] ?? $service->user->email,
        ];
    }
}
