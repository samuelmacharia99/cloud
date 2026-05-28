<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDomain;
use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\DatabaseTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
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
    private const HEALTH_CHECK_RETRIES = 24;
    private const HEALTH_CHECK_DELAY = 5;

    /**
     * Deploy a service as a Docker Compose container
     */
    public function deploy(Service $service): void
    {
        $deployStartedAt = microtime(true);

        try {
            // Load relationships
            $service->load('product.containerTemplate', 'user', 'node');

            // Validation
            if (! $service->product || ! $service->product->containerTemplate) {
                throw new \DomainException('Service must have a container template');
            }

            $template = $service->product->containerTemplate;
            \Log::info('Container deploy started', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'template_id' => $template->id,
                'template_slug' => $template->slug,
            ]);
            $this->recordDeploymentEvent($service, null, 'deploy_started', [
                'template_id' => $template->id,
                'template_slug' => $template->slug,
            ]);

            // Select node if not already set
            if (! $service->node_id) {
                $node = $this->selectNode($template);
                $service->update(['node_id' => $node->id]);
            } else {
                $node = $service->node;
            }

            if (! $node || $node->type !== 'container_host' || ! $node->is_active) {
                throw new \DomainException('No active container host node available');
            }

            \Log::info('Container deploy node selected', [
                'service_id' => $service->id,
                'node_id' => $node->id,
                'node_hostname' => $node->hostname,
            ]);
            $this->recordDeploymentEvent($service, null, 'node_selected', [
                'node_id' => $node->id,
                'node_hostname' => $node->hostname,
            ]);

            // Generate container name: user-{user_id}-service-{service_id}-{template_type}
            $templateSlug = strtolower(str_replace(' ', '-', $template->slug));
            $containerName = "user-{$service->user_id}-service-{$service->id}-{$templateSlug}";

            // Load selected database if any
            $databaseTemplate = null;
            if (!empty($service->service_meta['database_id'])) {
                $databaseTemplate = DatabaseTemplate::find($service->service_meta['database_id']);
            }

            // Get selected version for templated containers
            $selectedVersion = $service->service_meta['selected_version'] ?? null;

            // Collect environment variables
            $envValues = $service->service_meta['env_values'] ?? [];
            $envVars = [];

            // Reserve port and persist deployment with retry in case of concurrent allocation collisions.
            $deployment = null;
            $port = null;
            $maxAttempts = 5;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    [$deployment, $port, $envVars] = DB::transaction(function () use (
                        $service,
                        $node,
                        $containerName,
                        $template,
                        $envValues,
                        $databaseTemplate,
                        $selectedVersion
                    ) {
                        // Serialize reservation/allocation by locking node row.
                        Node::whereKey($node->id)->lockForUpdate()->first();

                        $port = $this->assignPort($node);
                        $envVars = $this->buildEnvironmentVariables($template, $envValues, $service, $databaseTemplate, $port);

                        // Always reuse the most recent deployment row for this service.
                        // Using an older row can violate unique(container_name) during redeploy.
                        $existingDeployment = ContainerDeployment::where('service_id', $service->id)
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->first();
                        if ($existingDeployment) {
                            $existingDeployment->update([
                                'node_id' => $node->id,
                                'container_name' => $containerName,
                                'status' => 'deploying',
                                'docker_compose_content' => '',
                                'assigned_port' => $port,
                                'env_values' => $envVars,
                                'selected_version' => $selectedVersion,
                            ]);
                            return [$existingDeployment, $port, $envVars];
                        }

                        $newDeployment = ContainerDeployment::create([
                            'service_id' => $service->id,
                            'node_id' => $node->id,
                            'container_name' => $containerName,
                            'status' => 'deploying',
                            'docker_compose_content' => '',
                            'assigned_port' => $port,
                            'env_values' => $envVars,
                            'selected_version' => $selectedVersion,
                        ]);

                        return [$newDeployment, $port, $envVars];
                    });

                    break;
                } catch (QueryException $e) {
                    // Retry only duplicate-key collisions (e.g., assigned_port uniqueness).
                    if (($e->getCode() !== '23000' && $e->getCode() !== '23505') || $attempt === $maxAttempts) {
                        throw $e;
                    }
                }
            }

            if (! $deployment || ! $port) {
                throw new \RuntimeException('Failed to reserve a unique deployment port after retries');
            }
            $this->recordDeploymentEvent($service, $deployment, 'port_reserved', [
                'node_id' => $node->id,
                'assigned_port' => $port,
            ]);

            // Render docker-compose.yml with deployment
            $hostAppPath = $this->resolveHostAppPath($template, $containerName);
            $composeYaml = $this->renderCompose($template, $containerName, $port, $envVars, $databaseTemplate, $deployment, $selectedVersion, $hostAppPath);
            $deployment->update(['docker_compose_content' => $composeYaml]);

            // Update service status
            $service->update(['status' => 'provisioning']);

            // Execute deployment
            $ssh = SSHService::forNode($node);

            try {
                // Create container directory
                $containerPath = self::CONTAINER_BASE_PATH . '/' . $containerName;
                $ssh->mkdirp($containerPath);

                // Prepare application source on host path (git clone/pull) before starting compose.
                if ($hostAppPath) {
                    $this->syncApplicationSource($ssh, $service, $template, $hostAppPath);
                }

                // Upload docker-compose.yml
                $ssh->upload($composeYaml, $containerPath . '/docker-compose.yml');

                // Deploy container
                $ssh->exec(
                    "cd {$containerPath} && docker compose up -d",
                    self::DEPLOY_TIMEOUT
                );

                // Ensure runtime placeholder files also exist inside the live container.
                // This covers cases where host/template metadata drift prevents host-side
                // file sync from surfacing in the mounted app path.
                if ($hostAppPath || (($template->slug ?? null) === 'laravel')) {
                    $this->ensureDefaultLandingPageInContainer($ssh, $containerName);
                }

                // Health behavior is template-configurable: strict templates fail on timeout,
                // relaxed templates continue for smoother redeploys while still logging warnings.
                $strictHealthCheck = $this->isStrictHealthCheckEnabled($template);
                $healthTimeoutSeconds = $this->healthCheckTimeoutSeconds($template);
                try {
                    $this->waitForContainerHealth($ssh, $containerName, $healthTimeoutSeconds);
                    $this->recordDeploymentEvent($service, $deployment, 'health_check_passed', [
                        'container_name' => $containerName,
                        'strict' => $strictHealthCheck,
                        'timeout_seconds' => $healthTimeoutSeconds,
                    ]);
                } catch (\Exception $healthException) {
                    if ($strictHealthCheck) {
                        throw $healthException;
                    }

                    \Log::warning("Container health check timed out but continuing (relaxed mode) for service {$service->id}", [
                        'container_name' => $containerName,
                        'timeout_seconds' => $healthTimeoutSeconds,
                        'error' => $healthException->getMessage(),
                    ]);
                    $this->recordDeploymentEvent($service, $deployment, 'health_check_timed_out_relaxed', [
                        'container_name' => $containerName,
                        'strict' => false,
                        'timeout_seconds' => $healthTimeoutSeconds,
                        'error' => $healthException->getMessage(),
                    ]);
                }

                // Execute setup commands
                if ($template->setup_commands && is_array($template->setup_commands)) {
                    foreach ($template->setup_commands as $command) {
                        if (!empty($command)) {
                            try {
                                if (! $this->isSafeSetupCommand((string) $command)) {
                                    \Log::warning("Skipped unsafe setup command for service {$service->id}", [
                                        'command' => $command,
                                    ]);
                                    continue;
                                }
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

                // Ensure existing bound domains always follow the latest deployment
                // row/port after redeploys, otherwise nginx may point to stale ports.
                $this->reattachAndRebindDomains($service, $deployment);

                // Increment container count on node
                $node->increment('container_count');

                // Notify user
                app(NotificationService::class)->notifyServiceActivated($service->fresh());

                \Log::info("Container deployment successful for service {$service->id}", [
                    'container' => $containerName,
                    'node' => $node->id,
                    'port' => $port,
                    'duration_ms' => (int) ((microtime(true) - $deployStartedAt) * 1000),
                ]);
                $this->recordDeploymentEvent($service, $deployment, 'deploy_succeeded', [
                    'node_id' => $node->id,
                    'assigned_port' => $port,
                    'duration_ms' => (int) ((microtime(true) - $deployStartedAt) * 1000),
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
                $this->recordDeploymentEvent($service, $deployment, 'deploy_failed', [
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException("Container deployment failed: " . $e->getMessage(), 0, $e);
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            // Ensure state is consistent even for non-SSH failures (e.g. health-check timeout).
            try {
                $latestDeployment = ContainerDeployment::where('service_id', $service->id)
                    ->orderByDesc('id')
                    ->first();

                if ($latestDeployment && $latestDeployment->status !== 'failed') {
                    $latestDeployment->update([
                        'status' => 'failed',
                        'last_status_check_at' => now(),
                        'last_status_check_output' => $e->getMessage(),
                    ]);
                }

                if ($service->status !== 'failed') {
                    $service->update(['status' => 'failed']);
                }
            } catch (\Throwable $stateError) {
                \Log::warning("Failed to set deployment failure state for service {$service->id}", [
                    'error' => $stateError->getMessage(),
                ]);
            }

            \Log::error("Container provisioning error for service {$service->id}: " . $e->getMessage(), [
                'exception' => $e,
                'duration_ms' => (int) ((microtime(true) - $deployStartedAt) * 1000),
            ]);
            $this->recordDeploymentEvent($service, null, 'deploy_failed', [
                'error' => $e->getMessage(),
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

            // Validate node has SSH credentials before attempting operation
            $this->validateNodeSSHCredentials($deployment->node);

            $ssh = SSHService::forNode($deployment->node);

            try {
                // Ensure docker-compose.yml exists
                $this->ensureComposeFileExists($ssh, $deployment);

                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml stop", self::DEPLOY_TIMEOUT);

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

            // Validate node has SSH credentials before attempting operation
            $this->validateNodeSSHCredentials($deployment->node);

            $ssh = SSHService::forNode($deployment->node);

            try {
                // Ensure docker-compose.yml exists
                $this->ensureComposeFileExists($ssh, $deployment);

                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;

                // Parse docker-compose.yml to extract service names and container names
                $composeFile = $containerPath . '/docker-compose.yml';
                $composeContent = $ssh->exec("cat {$composeFile}");
                $composeData = Yaml::parse($composeContent);

                // Force remove any conflicting containers with explicit container_name
                if (isset($composeData['services'])) {
                    foreach ($composeData['services'] as $serviceName => $serviceConfig) {
                        if (isset($serviceConfig['container_name'])) {
                            $containerName = $serviceConfig['container_name'];
                            // Force remove the container if it exists
                            @$ssh->exec("docker rm -f {$containerName}", 10);
                            \Log::debug("Force removed container: {$containerName}");
                        }
                    }
                }

                // Stop and remove all containers/networks, then start fresh
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down --remove-orphans", self::DEPLOY_TIMEOUT);

                // Now start the containers
                $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml up -d", self::DEPLOY_TIMEOUT);

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

                // Validate node has SSH credentials before attempting operation
                $this->validateNodeSSHCredentials($node);

                $ssh = SSHService::forNode($node);

                try {
                    $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;

                    // Stop and remove containers
                    @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down -v", self::DEPLOY_TIMEOUT);

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

            // Validate node has SSH credentials before attempting operation
            $this->validateNodeSSHCredentials($deployment->node);

            $ssh = SSHService::forNode($deployment->node);

            try {
                // Ensure docker-compose.yml exists
                $this->ensureComposeFileExists($ssh, $deployment);

                $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml restart", self::DEPLOY_TIMEOUT);

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
                    "cd {$containerPath} && docker compose -f docker-compose.yml logs --no-color --tail={$lines}",
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
    private function selectNode($template = null): Node
    {
        $nodes = Node::where('type', 'container_host')
            ->where('is_active', true)
            ->orderBy('container_count')
            ->get();

        if ($nodes->isEmpty()) {
            throw new \DomainException('No available container host nodes');
        }

        if (! $template) {
            return $nodes->first();
        }

        $requiredCpuCores = (float) ($template->required_cpu_cores ?? 0);
        $requiredRamGb = (float) (($template->required_ram_mb ?? 0) / 1024);
        $requiredStorageGb = (float) ($template->required_storage_gb ?? 0);

        $node = $nodes->first(function (Node $node) use ($requiredCpuCores, $requiredRamGb, $requiredStorageGb) {
            $availableCpu = (float) $node->getAvailableCpuCores();
            $availableRam = (float) $node->getAvailableRamGb();
            $availableStorage = (float) $node->getAvailableStorageGb();

            return $availableCpu >= $requiredCpuCores
                && $availableRam >= $requiredRamGb
                && $availableStorage >= $requiredStorageGb;
        });

        if (! $node) {
            throw new \DomainException('No container host has enough available resources for this template');
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
            ->lockForUpdate()
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
    private function renderCompose($template, string $containerName, int $port, array $envVars, ?DatabaseTemplate $databaseTemplate = null, ?ContainerDeployment $deployment = null, ?string $selectedVersion = null, ?string $hostAppPath = null): string
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

        // Ensure Laravel templates always have a long-running web process.
        // This also covers legacy php:*fpm images from earlier seeded templates.
        if (($template->slug ?? null) === 'laravel' && str_starts_with($dockerImage, 'php:')) {
            $internalPort = (int) ($template->default_port ?: 8000);
            $compose['services'][$containerName]['working_dir'] = '/app';
            $compose['services'][$containerName]['command'] = "sh -lc \"php -S 0.0.0.0:{$internalPort} -t public || php -S 0.0.0.0:{$internalPort}\"";
        }

        // Add volumes
        if ($template->volume_paths) {
            $compose['services'][$containerName]['volumes'] = [];
            $compose['volumes'] = [];

            foreach ($template->volume_paths as $volumeName => $mountPath) {
                if ($volumeName === 'app_data' && $hostAppPath) {
                    $compose['services'][$containerName]['volumes'][] = "{$hostAppPath}:{$mountPath}";
                    continue;
                }

                $compose['services'][$containerName]['volumes'][] = "{$volumeName}:{$mountPath}";
                $compose['volumes'][$volumeName] = null;
            }

            if (empty($compose['volumes'])) {
                unset($compose['volumes']);
            }
        }

        // Legacy fallback: ensure runtime templates still mount host app path
        // even when template volume metadata is missing.
        if ($hostAppPath && empty($compose['services'][$containerName]['volumes'])) {
            $compose['services'][$containerName]['volumes'] = ["{$hostAppPath}:/app"];
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
    private function waitForContainerHealth(SSHService $ssh, string $containerName, int $timeoutSeconds): void
    {
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / self::HEALTH_CHECK_DELAY));
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $status = $this->getContainerStatus($ssh, $containerName);

                \Log::info("Health check attempt", [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
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
                    'max_attempts' => $maxAttempts,
                    'container_name' => $containerName,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts - 1) {
                sleep(self::HEALTH_CHECK_DELAY);
            }
        }

        throw new \RuntimeException("Container failed to reach healthy state after {$timeoutSeconds} seconds");
    }

    private function isStrictHealthCheckEnabled($template): bool
    {
        if (isset($template->strict_health_check)) {
            return (bool) $template->strict_health_check;
        }

        return true;
    }

    private function healthCheckTimeoutSeconds($template): int
    {
        $defaultTimeout = self::HEALTH_CHECK_RETRIES * self::HEALTH_CHECK_DELAY;
        $raw = $template->health_check_timeout_seconds ?? $defaultTimeout;
        $timeout = (int) $raw;

        return max(30, min(900, $timeout));
    }

    private function resolveHostAppPath($template, string $containerName): ?string
    {
        if (!isset($template->volume_paths) || !is_array($template->volume_paths)) {
            // Legacy template rows may miss volume_paths; still keep app path
            // for runtime templates that expect /app content.
            if (in_array($template->slug ?? '', ['laravel', 'php', 'nodejs', 'python', 'ruby'], true)) {
                return self::CONTAINER_BASE_PATH . '/' . $containerName . '/app';
            }
            return null;
        }

        if (!array_key_exists('app_data', $template->volume_paths)) {
            if (in_array($template->slug ?? '', ['laravel', 'php', 'nodejs', 'python', 'ruby'], true)) {
                return self::CONTAINER_BASE_PATH . '/' . $containerName . '/app';
            }
            return null;
        }

        return self::CONTAINER_BASE_PATH . '/' . $containerName . '/app';
    }

    private function syncApplicationSource(SSHService $ssh, Service $service, $template, string $hostAppPath): void
    {
        $ssh->mkdirp($hostAppPath);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $repoUrl = trim((string) ($meta['source_repo_url'] ?? ''));
        $branch = trim((string) ($meta['source_repo_branch'] ?? 'main'));
        if ($branch === '') {
            $branch = 'main';
        }

        // If no source configured, keep folder available for uploads/file manager usage.
        if ($repoUrl === '') {
            $ssh->exec("sh -lc " . escapeshellarg("mkdir -p " . escapeshellarg($hostAppPath) . " && touch " . escapeshellarg($hostAppPath . '/.keep')), 20);
            $this->ensureDefaultLandingPage($ssh, $hostAppPath);
            return;
        }

        // Best-effort clone/pull workflow.
        // Uses host git so application files persist and can be mounted to container path.
        $pathArg = escapeshellarg($hostAppPath);
        $repoArg = escapeshellarg($repoUrl);
        $branchArg = escapeshellarg($branch);
        $script = "set -e; "
            . "if [ -d {$pathArg}/.git ]; then "
            . "cd {$pathArg}; "
            . "git fetch --depth=1 origin {$branchArg}; "
            . "git checkout -f {$branchArg}; "
            . "git reset --hard FETCH_HEAD; "
            . "else "
            . "rm -rf {$pathArg}; "
            . "git clone --depth=1 --branch {$branchArg} {$repoArg} {$pathArg}; "
            . "fi";

        try {
            $ssh->exec("sh -lc " . escapeshellarg($script), 120);
        } catch (\Exception $e) {
            \Log::warning("Failed to sync application source for service {$service->id}", [
                'service_id' => $service->id,
                'branch' => $branch,
                'error' => $e->getMessage(),
            ]);
            $this->ensureDefaultLandingPage($ssh, $hostAppPath);
            // Continue deployment; user can still upload files manually.
        }
    }

    private function ensureDefaultLandingPage(SSHService $ssh, string $hostAppPath): void
    {
        $placeholderHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to Talksasa Cloud</title>
  <style>
    :root { color-scheme: dark; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: Arial, sans-serif;
      background: #0f172a;
      color: #e2e8f0;
      text-align: center;
      padding: 24px;
    }
    .card {
      max-width: 680px;
      padding: 32px;
      border-radius: 16px;
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(148, 163, 184, 0.35);
    }
    h1 { margin: 0 0 10px; font-size: 2rem; }
    p { margin: 0; color: #cbd5e1; font-size: 1.05rem; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Welcome to Talksasa Cloud</h1>
    <p>Your digital infrastructure partner.</p>
  </main>
</body>
</html>
HTML;

        $encodedHtml = base64_encode($placeholderHtml);
        $pathArg = escapeshellarg($hostAppPath);
        $indexArg = escapeshellarg($hostAppPath . '/index.html');
        $publicDirArg = escapeshellarg($hostAppPath . '/public');
        $publicIndexArg = escapeshellarg($hostAppPath . '/public/index.html');

        $script = "set -e; "
            . "mkdir -p {$pathArg}; "
            . "if [ ! -f {$indexArg} ]; then "
            . "printf %s " . escapeshellarg($encodedHtml) . " | base64 -d > {$indexArg}; "
            . "fi; "
            . "mkdir -p {$publicDirArg}; "
            . "if [ ! -f {$publicIndexArg} ]; then "
            . "printf %s " . escapeshellarg($encodedHtml) . " | base64 -d > {$publicIndexArg}; "
            . "fi";

        try {
            $ssh->exec("sh -lc " . escapeshellarg($script), 20);
        } catch (\Exception $e) {
            \Log::warning('Failed to write default placeholder page', [
                'host_app_path' => $hostAppPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureDefaultLandingPageInContainer(SSHService $ssh, string $containerName): void
    {
        $placeholderHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to Talksasa Cloud</title>
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;text-align:center;padding:24px;">
  <main style="max-width:680px;padding:32px;border-radius:16px;background:rgba(15,23,42,0.85);border:1px solid rgba(148,163,184,0.35);">
    <h1 style="margin:0 0 10px;font-size:2rem;">Welcome to Talksasa Cloud</h1>
    <p style="margin:0;color:#cbd5e1;font-size:1.05rem;">Your digital infrastructure partner.</p>
  </main>
</body>
</html>
HTML;

        $encodedHtml = base64_encode($placeholderHtml);
        $containerArg = escapeshellarg($containerName);
        $encodedArg = escapeshellarg($encodedHtml);
        $script = "set -e; "
            . "mkdir -p /app /app/public; "
            . "if [ ! -f /app/index.html ]; then printf %s {$encodedArg} | base64 -d > /app/index.html; fi; "
            . "if [ ! -f /app/public/index.html ]; then printf %s {$encodedArg} | base64 -d > /app/public/index.html; fi";

        try {
            $ssh->exec("docker exec -u 0 {$containerArg} sh -lc " . escapeshellarg($script), 20);
        } catch (\Exception $e) {
            \Log::warning('Failed to write default placeholder page inside container', [
                'container_name' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get container status from docker ps
     */
    private function getContainerStatus(SSHService $ssh, string $containerName): array
    {
        // Use docker inspect for compatibility across Docker versions.
        // docker ps --format json is not universally supported and can cause false negatives.
        $safeName = escapeshellarg($containerName);
        $statusOutput = trim($ssh->exec(
            "docker inspect --type container --format '{{.State.Status}}|{{.State.Running}}' {$safeName} 2>/dev/null || echo ''",
            10
        ));

        if ($statusOutput === '') {
            \Log::debug("Container not found in docker inspect", ['container_name' => $containerName]);
            return [
                'state' => 'unknown',
                'running' => false,
                'internal_ip' => null,
            ];
        }

        [$state, $runningRaw] = array_pad(explode('|', $statusOutput, 2), 2, '');
        $state = trim($state) !== '' ? trim($state) : 'unknown';
        $isRunning = strtolower(trim($runningRaw)) === 'true';

        // Best-effort port info for diagnostics/UI (can be empty for internal-only services).
        $ports = trim($ssh->exec(
            "docker inspect --type container --format '{{json .NetworkSettings.Ports}}' {$safeName} 2>/dev/null || echo ''",
            10
        ));

        \Log::debug("Container status check", [
            'container_name' => $containerName,
            'state' => $state,
            'running' => $isRunning,
            'ports' => $ports,
        ]);

        return [
            'state' => $state,
            'running' => $isRunning,
            'internal_ip' => $ports !== '' ? $ports : null,
            'full_data' => [
                'state' => $state,
                'running' => $isRunning,
                'ports' => $ports,
            ],
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

    /**
     * Validate node has required SSH credentials for container operations
     */
    public function validateNodeSSHCredentials(Node $node): void
    {
        if (!$node->ssh_username) {
            throw new \DomainException(
                "Container host '{$node->hostname}' is not configured: missing SSH username. " .
                "An administrator needs to configure SSH credentials for this node."
            );
        }

        if (!$node->ssh_password && !$node->da_login_key) {
            throw new \DomainException(
                "Container host '{$node->hostname}' is not configured: missing SSH authentication (no password or key). " .
                "An administrator needs to configure SSH credentials for this node."
            );
        }
    }

    /**
     * Ensure docker-compose.yml file exists, re-uploading if necessary
     */
    public function ensureComposeFileExists(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;
        $composeFile = $containerPath . '/docker-compose.yml';

        // Check if file exists
        try {
            $ssh->exec("test -f {$composeFile}");
            return; // File exists
        } catch (\Exception $e) {
            // File doesn't exist, re-upload it
        }

        // Re-upload docker-compose.yml from stored content
        if (!$deployment->docker_compose_content) {
            throw new \RuntimeException(
                "docker-compose.yml file missing and no backup content stored. " .
                "Container deployment is corrupted. Please contact support."
            );
        }

        \Log::warning("Re-uploading docker-compose.yml for deployment {$deployment->id}");
        $ssh->upload($deployment->docker_compose_content, $composeFile);
    }

    /**
     * Basic safety validation for template setup commands.
     */
    private function isSafeSetupCommand(string $command): bool
    {
        $cmd = trim($command);
        if ($cmd === '' || strlen($cmd) > 500) {
            return false;
        }

        // Block common shell control/injection characters.
        if (preg_match('/[;&|`$<>\\\\]/', $cmd)) {
            return false;
        }

        return true;
    }

    /**
     * Persist deployment lifecycle events for audit/incident timelines.
     */
    private function recordDeploymentEvent(Service $service, ?ContainerDeployment $deployment, string $event, array $payload = []): void
    {
        try {
            ContainerDeploymentEvent::create([
                'service_id' => $service->id,
                'container_deployment_id' => $deployment?->id,
                'event' => $event,
                'payload' => $payload ?: null,
                'recorded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning("Failed to record container deployment event '{$event}'", [
                'service_id' => $service->id,
                'deployment_id' => $deployment?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reattachAndRebindDomains(Service $service, ContainerDeployment $latestDeployment): void
    {
        try {
            $domains = ContainerDomain::whereHas('deployment', function ($query) use ($service) {
                $query->where('service_id', $service->id);
            })->get();

            if ($domains->isEmpty()) {
                return;
            }

            $nginxService = new NginxProxyService();
            foreach ($domains as $domain) {
                if ($domain->container_deployment_id !== $latestDeployment->id) {
                    $domain->update(['container_deployment_id' => $latestDeployment->id]);
                }

                // Keep active/pending domains pointed to current assigned port.
                if (in_array($domain->status, ['active', 'pending'], true)) {
                    try {
                        $nginxService->bind($domain->fresh());
                    } catch (\Throwable $domainError) {
                        \Log::warning('Failed to rebind container domain after redeploy', [
                            'service_id' => $service->id,
                            'domain' => $domain->domain,
                            'error' => $domainError->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to reattach domains to latest deployment', [
                'service_id' => $service->id,
                'deployment_id' => $latestDeployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
