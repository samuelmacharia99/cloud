<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\DatabaseTemplate;
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
    private const HEALTH_CHECK_RETRIES = 9;
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

            // Generate container name: user-{user_id}-service-{service_id}-{template_type}
            $templateSlug = strtolower(str_replace(' ', '-', $template->slug));
            $containerName = "user-{$service->user_id}-service-{$service->id}-{$templateSlug}";

            // Assign port
            $port = $this->assignPort($node);

            // Load selected database if any
            $databaseTemplate = null;
            if (!empty($service->service_meta['database_id'])) {
                $databaseTemplate = DatabaseTemplate::find($service->service_meta['database_id']);
            }

            // Get selected version for templated containers
            $selectedVersion = $service->service_meta['selected_version'] ?? null;

            // Collect environment variables
            $envValues = $service->service_meta['env_values'] ?? [];
            $envVars = $this->buildEnvironmentVariables($template, $envValues, $service, $databaseTemplate, $port);

            // Check if deployment already exists for this service and reuse it
            $existingDeployment = ContainerDeployment::where('service_id', $service->id)->first();

            if ($existingDeployment) {
                // Update existing deployment (in case of retry after failure)
                $deployment = $existingDeployment;
                $deployment->update([
                    'node_id' => $node->id,
                    'status' => 'deploying',
                    'docker_compose_content' => '',
                    'assigned_port' => $port,
                    'env_values' => $envVars,
                    'selected_version' => $selectedVersion,
                ]);
            } else {
                // Create new deployment record
                $deployment = ContainerDeployment::create([
                    'service_id' => $service->id,
                    'node_id' => $node->id,
                    'container_name' => $containerName,
                    'status' => 'deploying',
                    'docker_compose_content' => '', // Will be set after rendering
                    'assigned_port' => $port,
                    'env_values' => $envVars,
                    'selected_version' => $selectedVersion,
                ]);
            }

            // Render docker-compose.yml with deployment
            $composeYaml = $this->renderCompose($template, $containerName, $port, $envVars, $databaseTemplate, $deployment, $selectedVersion);
            $deployment->update(['docker_compose_content' => $composeYaml]);

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

                // Note: Skip blocking health check. Containers may take 10-30+ seconds to fully start.
                // Background cron job (cron:check-container-health) will verify health periodically.
                \Log::info("Container deployed successfully, health verification via background job", [
                    'service_id' => $service->id,
                    'container_name' => $containerName,
                ]);

                // Execute setup commands
                if ($template->setup_commands && is_array($template->setup_commands)) {
                    foreach ($template->setup_commands as $command) {
                        if (!empty($command)) {
                            try {
                                $ssh->exec("cd {$containerPath} && {$command}", self::DEPLOY_TIMEOUT);
                            } catch (\Exception $e) {
                                \Log::warning("Setup command failed for service {$service->id}: {$command}", [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }

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

                // Increment container count on node
                $node->increment('container_count');

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

                // Notify user of suspension
                app(NotificationService::class)->notifyServiceSuspended($service->fresh());

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
                $node = $deployment->node;
                $ssh = SSHService::forNode($node);

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

                    // Decrement container count on node
                    $node->decrement('container_count');

                    \Log::info("Container terminated for service {$service->id}");
                } finally {
                    $ssh->disconnect();
                }
            }

            $service->update([
                'status' => 'terminated',
                'terminate_date' => now(),
            ]);

            // Notify user of termination
            app(NotificationService::class)->notifyServiceTerminated($service->fresh());
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

                $deployment->update([
                    'last_status_check_at' => now(),
                    'last_restart_at'      => now(),
                ]);
                $deployment->increment('restart_attempts');

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
     * Build complete environment variables including system vars and database connection
     */
    private function buildEnvironmentVariables($template, array $userValues, Service $service, ?DatabaseTemplate $databaseTemplate = null, ?int $port = null): array
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
        $env['APP_PORT'] = (string) ($port ?? $this->assignPort($service->node));
        $env['DATA_DIR'] = '/data';
        $env['COMPOSE_PROJECT_NAME'] = 'talksasa-' . $service->id;

        // Generate secrets if needed
        if (! isset($env['DB_PASSWORD']) || ! $env['DB_PASSWORD']) {
            $env['DB_PASSWORD'] = Str::random(32);
        }
        if (! isset($env['ADMIN_PASSWORD']) || ! $env['ADMIN_PASSWORD']) {
            $env['ADMIN_PASSWORD'] = Str::random(20);
        }

        // Add database connection env vars if database is selected
        if ($databaseTemplate) {
            $dbVars = match($databaseTemplate->type) {
                'mysql', 'mariadb' => [
                    'MYSQL_ROOT_PASSWORD' => $env['DB_PASSWORD'] ?? Str::random(32),
                    'MYSQL_DATABASE' => $env['MYSQL_DATABASE'] ?? 'appdb',
                    'MYSQL_USER' => $env['MYSQL_USER'] ?? 'appuser',
                    'MYSQL_PASSWORD' => $env['MYSQL_PASSWORD'] ?? ($env['DB_PASSWORD'] ?? Str::random(32)),
                    'DB_HOST' => 'db',
                    'DB_PORT' => '3306',
                    'DB_DATABASE' => $env['MYSQL_DATABASE'] ?? 'appdb',
                    'DB_USERNAME' => $env['MYSQL_USER'] ?? 'appuser',
                    'DB_PASSWORD' => $env['DB_PASSWORD'] ?? Str::random(32),
                ],
                'postgresql' => [
                    'POSTGRES_PASSWORD' => $env['DB_PASSWORD'] ?? Str::random(32),
                    'POSTGRES_DB' => $env['POSTGRES_DB'] ?? 'appdb',
                    'POSTGRES_USER' => $env['POSTGRES_USER'] ?? 'appuser',
                    'DATABASE_URL' => 'postgresql://appuser:' . ($env['DB_PASSWORD'] ?? Str::random(32)) . '@db:5432/appdb',
                ],
                'mongodb' => [
                    'MONGO_INITDB_ROOT_USERNAME' => $env['MONGO_INITDB_ROOT_USERNAME'] ?? 'appuser',
                    'MONGO_INITDB_ROOT_PASSWORD' => $env['MONGO_INITDB_ROOT_PASSWORD'] ?? ($env['DB_PASSWORD'] ?? Str::random(32)),
                    'MONGO_INITDB_DATABASE' => $env['MONGO_INITDB_DATABASE'] ?? 'appdb',
                    'MONGODB_URI' => 'mongodb://appuser:' . ($env['DB_PASSWORD'] ?? Str::random(32)) . '@db:27017/appdb',
                ],
                'redis' => [
                    'REDIS_HOST' => 'db',
                    'REDIS_PORT' => '6379',
                    'REDIS_URL' => 'redis://db:6379',
                ],
                default => [],
            };
            $env = array_merge($env, $dbVars);
        }

        return $env;
    }

    /**
     * Inject database sidecar service into compose array
     */
    private function injectDatabaseSidecar(
        array &$compose,
        DatabaseTemplate $db,
        array $envVars,
        string $appServiceName
    ): void {
        $dbEnv = match($db->type) {
            'mysql', 'mariadb' => [
                'MYSQL_ROOT_PASSWORD' => $envVars['MYSQL_ROOT_PASSWORD'] ?? Str::random(32),
                'MYSQL_DATABASE' => $envVars['MYSQL_DATABASE'] ?? 'appdb',
                'MYSQL_USER' => $envVars['MYSQL_USER'] ?? 'appuser',
                'MYSQL_PASSWORD' => $envVars['MYSQL_PASSWORD'] ?? Str::random(32),
            ],
            'postgresql' => [
                'POSTGRES_PASSWORD' => $envVars['POSTGRES_PASSWORD'] ?? Str::random(32),
                'POSTGRES_DB' => $envVars['POSTGRES_DB'] ?? 'appdb',
                'POSTGRES_USER' => $envVars['POSTGRES_USER'] ?? 'appuser',
            ],
            'mongodb' => [
                'MONGO_INITDB_ROOT_USERNAME' => $envVars['MONGO_INITDB_ROOT_USERNAME'] ?? 'appuser',
                'MONGO_INITDB_ROOT_PASSWORD' => $envVars['MONGO_INITDB_ROOT_PASSWORD'] ?? Str::random(32),
                'MONGO_INITDB_DATABASE' => $envVars['MONGO_INITDB_DATABASE'] ?? 'appdb',
            ],
            'redis' => [],
            default => [],
        };

        $mountPath = match($db->type) {
            'mysql', 'mariadb' => '/var/lib/mysql',
            'postgresql' => '/var/lib/postgresql/data',
            'mongodb' => '/data/db',
            'redis' => '/data',
            default => '/data',
        };

        $compose['services']['db'] = array_filter([
            'image' => $db->docker_image,
            'restart' => 'unless-stopped',
            'environment' => $dbEnv ?: null,
            'volumes' => ["db_data:{$mountPath}"],
        ]);

        $compose['volumes']['db_data'] = null;
        $compose['services'][$appServiceName]['depends_on'] = ['db'];
    }

    /**
     * Render docker-compose.yml from template with optional database sidecar
     */
    private function renderCompose($template, string $containerName, int $port, array $envVars, ?DatabaseTemplate $databaseTemplate = null, ?ContainerDeployment $deployment = null, ?string $selectedVersion = null): string
    {
        // Determine resource limits (override > template)
        $cpuLimit = $deployment?->cpu_limit ?? $template->required_cpu_cores ?? 1.0;
        $memoryLimit = $deployment?->memory_limit_mb ?? $template->required_ram_mb ?? 256;

        // Convert to docker compose format
        $cpuLimitStr = (string) $cpuLimit;
        $memoryLimitStr = $memoryLimit . 'M';

        // Reservations at 50% of limits
        $cpuReservation = (string) ($cpuLimit * 0.5);
        $memoryReservation = (int) ($memoryLimit * 0.5) . 'M';

        // Resolve docker image with selected version
        $dockerImage = $template->docker_image;
        if ($selectedVersion && $template->versions) {
            // Handle versions as array or JSON string
            $versions = is_array($template->versions) ? $template->versions : json_decode($template->versions, true) ?? [];
            if (in_array($selectedVersion, $versions)) {
                // Extract image name from docker_image (e.g., "node" from "node:latest")
                $imageName = explode(':', $dockerImage)[0];
                $dockerImage = $imageName . ':' . $selectedVersion;
            }
        }

        $compose = [
            'version' => '3.9',
            'services' => [
                $containerName => [
                    'image' => $dockerImage,
                    'container_name' => $containerName,
                    'restart' => $deployment?->restart_policy ?? 'unless-stopped',
                    'environment' => $envVars,
                    'ports' => ["{$port}:" . $template->default_port],
                    'deploy' => [
                        'resources' => [
                            'limits' => [
                                'cpus' => $cpuLimitStr,
                                'memory' => $memoryLimitStr,
                            ],
                            'reservations' => [
                                'cpus' => $cpuReservation,
                                'memory' => $memoryReservation,
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

        // Add sidecar services from template
        if ($template->compose_services) {
            foreach ($template->compose_services as $serviceName => $serviceConfig) {
                $compose['services'][$serviceName] = $serviceConfig;
            }
        }

        // Inject database sidecar if selected
        if ($databaseTemplate) {
            $this->injectDatabaseSidecar($compose, $databaseTemplate, $envVars, $containerName);
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

                \Log::info("Health check attempt", [
                    'attempt' => $attempt + 1,
                    'max_attempts' => self::HEALTH_CHECK_RETRIES,
                    'container_name' => $containerName,
                    'running' => $status['running'] ?? false,
                    'state' => $status['state'] ?? 'unknown',
                ]);

                if (isset($status['running']) && $status['running']) {
                    \Log::info("Container health check passed", ['container_name' => $containerName]);
                    return;
                }
            } catch (\Exception $e) {
                \Log::warning("Health check exception", [
                    'attempt' => $attempt + 1,
                    'container_name' => $containerName,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::HEALTH_CHECK_RETRIES - 1) {
                sleep(self::HEALTH_CHECK_DELAY);
            }
        }

        throw new \RuntimeException("Container failed to reach healthy state after " . (self::HEALTH_CHECK_RETRIES * self::HEALTH_CHECK_DELAY) . " seconds");
    }

    /**
     * Get container status from docker ps
     */
    private function getContainerStatus(SSHService $ssh, string $containerName): array
    {
        // Use docker ps to check container status (more reliable than docker compose ps)
        $output = $ssh->exec("docker ps -a --filter 'name={$containerName}' --format json 2>/dev/null || echo '[]'", 10);

        $containers = json_decode($output, true) ?? [];

        if (empty($containers)) {
            \Log::debug("Container not found in docker ps", ['container_name' => $containerName]);
            return [
                'state' => 'unknown',
                'running' => false,
                'internal_ip' => null,
            ];
        }

        $mainContainer = $containers[0];
        $state = $mainContainer['State'] ?? 'unknown';
        $isRunning = stripos($state, 'up') !== false;

        \Log::debug("Container status check", [
            'container_name' => $containerName,
            'state' => $state,
            'running' => $isRunning,
            'full_data' => $mainContainer,
        ]);

        return [
            'state' => $state,
            'running' => $isRunning,
            'internal_ip' => $mainContainer['Ports'] ?? null,
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
