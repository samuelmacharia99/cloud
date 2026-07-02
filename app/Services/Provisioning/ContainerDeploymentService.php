<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerDomain;
use App\Models\DatabaseTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\SSH\SSHService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Production-grade container deployment service
 * Handles Docker Compose lifecycle management via SSH
 */
class ContainerDeploymentService
{
    public const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private RuntimeImageProvisioner $runtimeImages;

    private ContainerAppDirectoryService $appDirectory;

    private ContainerTemplateEnvironmentService $templateEnvironment;

    private ContainerStackCommandService $stackCommands;

    private ContainerApplicationRuntimeService $applicationRuntime;

    private const PORT_RANGE_START = 30000;

    private const PORT_RANGE_END = 40000;

    private const DEPLOY_TIMEOUT = 120;

    private const HEALTH_CHECK_RETRIES = 24;

    private const HEALTH_CHECK_DELAY = 5;

    public function __construct(
        ?RuntimeImageProvisioner $runtimeImages = null,
        ?ContainerAppDirectoryService $appDirectory = null,
        ?ContainerTemplateEnvironmentService $templateEnvironment = null,
        ?ContainerStackCommandService $stackCommands = null,
        ?ContainerApplicationRuntimeService $applicationRuntime = null,
    ) {
        $this->runtimeImages = $runtimeImages ?? new RuntimeImageProvisioner;
        $this->appDirectory = $appDirectory ?? new ContainerAppDirectoryService;
        $this->templateEnvironment = $templateEnvironment ?? new ContainerTemplateEnvironmentService;
        $this->stackCommands = $stackCommands ?? new ContainerStackCommandService;
        $this->applicationRuntime = $applicationRuntime ?? new ContainerApplicationRuntimeService;
    }

    /**
     * Deploy a service as a Docker Compose container
     */
    public function deploy(Service $service, ?ContainerDeployOptions $options = null): ContainerDeployResult
    {
        $options ??= new ContainerDeployOptions;
        $databaseReset = false;
        $laravelDatabaseSyncMessage = null;
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

            $databaseTemplate = $this->resolveDatabaseTemplate($service, $template);

            // Get selected version for templated containers
            $selectedVersion = $service->service_meta['selected_version'] ?? null;

            // Collect environment variables (preserve deployment secrets across redeploys).
            $existingDeployment = ContainerDeployment::where('service_id', $service->id)
                ->orderByDesc('id')
                ->first();
            $envValues = $service->service_meta['env_values'] ?? [];
            if ($existingDeployment && is_array($existingDeployment->env_values)) {
                $envValues = array_merge($existingDeployment->env_values, $envValues);
            }
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
                            $existingDeployment->update(array_merge([
                                'node_id' => $node->id,
                                'container_name' => $containerName,
                                'status' => 'deploying',
                                'docker_compose_content' => '',
                                'assigned_port' => $port,
                                'env_values' => $envVars,
                                'selected_version' => $selectedVersion,
                            ], $this->containerResourceLimitsForService($service)));

                            return [$existingDeployment, $port, $envVars];
                        }

                        $newDeployment = ContainerDeployment::create(array_merge([
                            'service_id' => $service->id,
                            'node_id' => $node->id,
                            'container_name' => $containerName,
                            'status' => 'deploying',
                            'docker_compose_content' => '',
                            'assigned_port' => $port,
                            'env_values' => $envVars,
                            'selected_version' => $selectedVersion,
                        ], $this->containerResourceLimitsForService($service)));

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

            // Update service status
            $service->update(['status' => 'provisioning']);

            // Execute deployment
            $ssh = SSHService::forNode($node);

            try {
                // Create container directory
                $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;
                $ssh->mkdirp($containerPath);

                if ($options->shouldResetDatabase($databaseTemplate !== null)) {
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: true);
                    $databaseReset = true;
                    $this->recordDeploymentEvent($service, $deployment, 'database_volume_reset', [
                        'container_name' => $containerName,
                    ]);
                } elseif ($options->isRedeploy) {
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: false);
                }

                // Prepare application source on host path (git clone/pull) before starting compose.
                if ($hostAppPath) {
                    $this->syncApplicationSource($ssh, $service, $template, $hostAppPath);
                }

                $applicationRuntime = $this->resolveApplicationRuntime($ssh, $template, $hostAppPath);
                $laravelDocumentRoot = ($template->slug ?? null) === 'laravel' && $hostAppPath
                    ? app(LaravelProjectPathResolver::class)->resolveDocumentRoot($ssh, $hostAppPath)
                    : null;
                if ($laravelDocumentRoot !== null) {
                    app(LaravelProjectPathResolver::class)->persistResolvedPaths($service, $ssh, $deployment);
                }
                $composeYaml = $this->renderCompose(
                    $template,
                    $containerName,
                    $port,
                    $envVars,
                    $databaseTemplate,
                    $deployment,
                    $selectedVersion,
                    $hostAppPath,
                    $applicationRuntime,
                    $laravelDocumentRoot
                );
                $deployment->update(['docker_compose_content' => $composeYaml]);

                // Upload docker-compose.yml
                $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

                if ($this->runtimeImages->usesRuntimeImage($template)) {
                    $this->runtimeImages->ensureImage($ssh, $template, $selectedVersion, $service, $deployment);
                }

                // Deploy container
                $ssh->exec(
                    "cd {$containerPath} && docker compose up -d",
                    self::DEPLOY_TIMEOUT
                );

                // Host mount is the source of truth for /app; ensure placeholders after compose is up.
                if ($hostAppPath) {
                    $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);
                }

                $this->appDirectory->normalizePermissions($ssh, $deployment);

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

                $this->appDirectory->normalizePermissions($ssh, $deployment);

                $this->stackCommands->executeSetupCommands(
                    $ssh,
                    $containerPath,
                    $containerName,
                    $template,
                    self::DEPLOY_TIMEOUT
                );

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
                $credentials = $this->generateCredentials($service, $deployment, $envVars, $databaseTemplate);
                $serviceMeta = is_array($service->service_meta) ? $service->service_meta : [];
                if ($databaseTemplate) {
                    $serviceMeta['database_id'] = $databaseTemplate->id;
                }
                $serviceMeta['env_values'] = $envVars;
                $service->update([
                    'status' => 'active',
                    'credentials' => json_encode($credentials),
                    'service_meta' => $serviceMeta,
                ]);

                // Ensure existing bound domains always follow the latest deployment
                // row/port after redeploys, otherwise nginx may point to stale ports.
                $this->reattachAndRebindDomains($service, $deployment);

                if ($options->shouldPrepareLaravelApplication((string) ($template->slug ?? ''))) {
                    try {
                        $laravelDatabaseSyncMessage = app(LaravelDatabaseSyncService::class)
                            ->syncIfInstalled(
                                $service,
                                $deployment->fresh(),
                                $ssh,
                                $options->shouldRunLaravelMigrations((string) ($template->slug ?? '')),
                                $options->isRedeploy,
                            );

                        if ($laravelDatabaseSyncMessage) {
                            $this->recordDeploymentEvent($service, $deployment, 'laravel_application_prepared', [
                                'message' => $laravelDatabaseSyncMessage,
                                'migrations' => $options->shouldRunLaravelMigrations((string) ($template->slug ?? '')),
                            ]);
                        }
                    } catch (\Throwable $syncError) {
                        $laravelDatabaseSyncMessage = 'Laravel application preparation failed: '.$syncError->getMessage();
                        \Log::warning($laravelDatabaseSyncMessage, [
                            'service_id' => $service->id,
                            'error' => $syncError->getMessage(),
                        ]);
                        $this->recordDeploymentEvent($service, $deployment, 'laravel_application_prepare_failed', [
                            'error' => $syncError->getMessage(),
                        ]);
                    }
                }

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

                return new ContainerDeployResult($databaseReset, $laravelDatabaseSyncMessage);
            } catch (SSHCommandException|SSHConnectionException $e) {
                $deployment->update([
                    'status' => 'failed',
                    'last_status_check_output' => $e->getMessage(),
                ]);

                $service->update(['status' => 'failed']);

                \Log::error("Container deployment failed for service {$service->id}: ".$e->getMessage(), [
                    'container' => $containerName,
                    'exception' => $e,
                ]);
                $this->recordDeploymentEvent($service, $deployment, 'deploy_failed', [
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException('Container deployment failed: '.$e->getMessage(), 0, $e);
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

            \Log::error("Container provisioning error for service {$service->id}: ".$e->getMessage(), [
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

                $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
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
            \Log::error("Failed to suspend container for service {$service->id}: ".$e->getMessage());

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

                $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

                // Parse docker-compose.yml to extract service names and container names
                $composeFile = $containerPath.'/docker-compose.yml';
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
            \Log::error("Failed to resume container for service {$service->id}: ".$e->getMessage());

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

            if ($deployment) {
                $this->unbindAllDomainsForService($service);
                $this->purgeBackupsForService($service);
            }

            if ($deployment && $deployment->node) {
                $node = $deployment->node;

                // Validate node has SSH credentials before attempting operation
                $this->validateNodeSSHCredentials($node);

                $ssh = SSHService::forNode($node);

                try {
                    $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

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
            \Log::error("Failed to terminate container for service {$service->id}: ".$e->getMessage());

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

                $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml restart", self::DEPLOY_TIMEOUT);

                $deployment->update([
                    'last_status_check_at' => now(),
                    'last_restart_at' => now(),
                ]);
                $deployment->increment('restart_attempts');

                \Log::info("Container restarted for service {$service->id}");
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: ".$e->getMessage());

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
                $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                $output = $ssh->exec(
                    "cd {$containerPath} && docker compose -f docker-compose.yml logs --no-color --tail={$lines}",
                    30
                );

                return $output;
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch container logs for service {$service->id}: ".$e->getMessage());

            return 'Error fetching logs: '.$e->getMessage();
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
            \Log::warning("Failed to get container status for service {$service->id}: ".$e->getMessage());

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

        throw new \DomainException('No available ports in range '.self::PORT_RANGE_START.'-'.self::PORT_RANGE_END);
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
        $env['COMPOSE_PROJECT_NAME'] = 'talksasa-'.$service->id;

        if (in_array($template->slug ?? '', ['nodejs', 'ruby'], true)) {
            $env['PORT'] = (string) ($template->default_port ?? 3000);
        }

        if (($template->slug ?? '') === 'python') {
            $env['PORT'] = (string) ($template->default_port ?? 8000);
        }

        // Generate secrets if needed
        if (! isset($env['DB_PASSWORD']) || ! $env['DB_PASSWORD']) {
            $env['DB_PASSWORD'] = Str::random(32);
        }
        if (! isset($env['ADMIN_PASSWORD']) || ! $env['ADMIN_PASSWORD']) {
            $env['ADMIN_PASSWORD'] = Str::random(20);
        }

        // Add database connection env vars if database is selected
        if ($databaseTemplate) {
            $env = array_merge($env, $this->databaseEnvironmentVariables($databaseTemplate, $env, $service));
        }

        return $this->templateEnvironment->prepare($template, $env, $service, $port);
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
        $dbEnv = match ($db->type) {
            'mysql', 'mariadb' => [
                'MYSQL_ROOT_PASSWORD' => $envVars['MYSQL_ROOT_PASSWORD'] ?? Str::random(32),
                'MYSQL_DATABASE' => $envVars['MYSQL_DATABASE'] ?? $envVars['DB_DATABASE'] ?? 'appdb',
                'MYSQL_USER' => $envVars['MYSQL_USER'] ?? $envVars['DB_USERNAME'] ?? 'appuser',
                'MYSQL_PASSWORD' => $envVars['MYSQL_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? Str::random(32),
            ],
            'postgresql' => [
                'POSTGRES_PASSWORD' => $envVars['POSTGRES_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? Str::random(32),
                'POSTGRES_DB' => $envVars['POSTGRES_DB'] ?? $envVars['DB_DATABASE'] ?? 'appdb',
                'POSTGRES_USER' => $envVars['POSTGRES_USER'] ?? $envVars['DB_USERNAME'] ?? 'appuser',
            ],
            'mongodb' => [
                'MONGO_INITDB_ROOT_USERNAME' => $envVars['MONGO_INITDB_ROOT_USERNAME'] ?? $envVars['DB_USERNAME'] ?? 'appuser',
                'MONGO_INITDB_ROOT_PASSWORD' => $envVars['MONGO_INITDB_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? Str::random(32),
                'MONGO_INITDB_DATABASE' => $envVars['MONGO_INITDB_DATABASE'] ?? $envVars['DB_DATABASE'] ?? 'appdb',
            ],
            'redis' => [],
            default => [],
        };

        $mountPath = match ($db->type) {
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
    private function renderCompose($template, string $containerName, int $port, array $envVars, ?DatabaseTemplate $databaseTemplate = null, ?ContainerDeployment $deployment = null, ?string $selectedVersion = null, ?string $hostAppPath = null, ?ApplicationRuntime $applicationRuntime = null, ?string $laravelDocumentRoot = null): string
    {
        // Determine resource limits (override > template)
        $cpuLimit = $deployment?->cpu_limit ?? $template->required_cpu_cores ?? 1.0;
        $memoryLimit = $deployment?->memory_limit_mb ?? $template->required_ram_mb ?? 256;

        // Convert to docker compose format
        $cpuLimitStr = (string) $cpuLimit;
        $memoryLimitStr = $memoryLimit.'M';

        // Reservations at 50% of limits
        $cpuReservation = (string) ($cpuLimit * 0.5);
        $memoryReservation = (int) ($memoryLimit * 0.5).'M';

        $dockerImage = $this->resolveDockerImage($template, $selectedVersion);

        $compose = [
            'services' => [
                $containerName => [
                    'image' => $dockerImage,
                    'container_name' => $containerName,
                    'restart' => $deployment?->restart_policy ?? 'unless-stopped',
                    'environment' => $envVars,
                    'ports' => ["{$port}:".$template->default_port],
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

        // Talksasa runtime images ship Composer, extensions, and entrypoint ownership fixes.
        if ($this->runtimeImages->usesRuntimeImage($template)) {
            $internalPort = (int) ($template->default_port ?: (($template->slug ?? null) === 'laravel' ? 8000 : 8080));
            $compose['services'][$containerName]['user'] = 'www-data';
            $compose['services'][$containerName]['working_dir'] = '/app';

            if (($template->slug ?? null) === 'laravel') {
                $documentRoot = $laravelDocumentRoot ?: '/app/public';
                $compose['services'][$containerName]['command'] = [
                    'php',
                    '-S',
                    "0.0.0.0:{$internalPort}",
                    '-t',
                    $documentRoot,
                ];
            } else {
                $compose['services'][$containerName]['command'] = [
                    'php',
                    '-S',
                    "0.0.0.0:{$internalPort}",
                    '-t',
                    '/app',
                ];
            }
        }

        if ($this->applicationRuntime->supportsTemplate($template->slug ?? null)) {
            $runtime = $applicationRuntime ?? $this->applicationRuntime->fallbackRuntime(
                (string) $template->slug,
                (int) ($template->default_port ?? 3000)
            );
            $compose['services'][$containerName]['working_dir'] = '/app';
            $compose['services'][$containerName]['command'] = $runtime->command;
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

        $this->templateEnvironment->syncEmbeddedDatabaseSidecar($compose, $template, $envVars, $containerName);

        // Inject database sidecar if selected and template does not already define one
        if ($databaseTemplate && ! $this->templateEnvironment->templateDefinesDatabaseSidecar($template)) {
            $this->injectDatabaseSidecar($compose, $databaseTemplate, $envVars, $containerName);
        }

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * Wait until the container is running (not restarting/exited).
     */
    public function waitForContainerRunning(SSHService $ssh, string $containerName, int $timeoutSeconds = 120): void
    {
        $this->waitForContainerHealth($ssh, $containerName, $timeoutSeconds);
    }

    public function waitForLaravelHttpHealth(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $timeoutSeconds = (int) config('containers.laravel_init.http_health_timeout_seconds', 90);
        $containerName = $deployment->container_name;
        $internalPort = 8000;
        $checkScript = 'wget -q -O /dev/null http://127.0.0.1:'.$internalPort.'/ 2>/dev/null'
            .' || curl -fsS -o /dev/null http://127.0.0.1:'.$internalPort.'/';

        $maxAttempts = max(1, (int) ceil($timeoutSeconds / self::HEALTH_CHECK_DELAY));
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $this->waitForContainerRunning($ssh, $containerName, self::HEALTH_CHECK_DELAY * 2);
                $ssh->exec(
                    'docker exec -u www-data -w / '.escapeshellarg($containerName)
                    .' sh -lc '.escapeshellarg($checkScript),
                    15
                );

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts - 1) {
                    sleep(self::HEALTH_CHECK_DELAY);
                }
            }
        }

        throw new \RuntimeException(
            'Laravel HTTP health check failed after '.$timeoutSeconds.' seconds'
            .($lastError ? ': '.$lastError->getMessage() : '.')
        );
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

                \Log::info('Health check attempt', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
                    'container_name' => $containerName,
                    'running' => $status['running'] ?? false,
                    'state' => $status['state'] ?? 'unknown',
                ]);

                if (isset($status['running']) && $status['running']) {
                    \Log::info('Container health check passed', ['container_name' => $containerName]);

                    return;
                }
            } catch (\Exception $e) {
                \Log::warning('Health check exception', [
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

    public function resolveDatabaseTemplateForService(Service $service): ?DatabaseTemplate
    {
        $service->loadMissing('product.containerTemplate');

        if (! $service->product?->containerTemplate) {
            return null;
        }

        return $this->resolveDatabaseTemplate($service, $service->product->containerTemplate);
    }

    public function waitForDatabaseSidecar(
        SSHService $ssh,
        string $containerPath,
        DatabaseTemplate $databaseTemplate,
        array $envVars,
        int $timeoutSeconds = 120
    ): void {
        $delaySeconds = 5;
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / $delaySeconds));
        $pathArg = escapeshellarg($containerPath);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                if ($this->databaseSidecarIsReady($ssh, $pathArg, $databaseTemplate, $envVars)) {
                    return;
                }
            } catch (\Throwable $e) {
                \Log::debug('Database readiness check failed', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($delaySeconds);
            }
        }

        throw new \RuntimeException('Database did not become ready within '.$timeoutSeconds.' seconds.');
    }

    public function waitForApplicationDatabaseAccess(
        SSHService $ssh,
        string $containerName,
        DatabaseTemplate $databaseTemplate,
        array $envVars,
        int $timeoutSeconds = 180
    ): void {
        $delaySeconds = 5;
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / $delaySeconds));
        $containerArg = escapeshellarg($containerName);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                if ($this->applicationDatabaseAccessReady($ssh, $containerArg, $databaseTemplate, $envVars)) {
                    return;
                }
            } catch (\Throwable $e) {
                \Log::debug('Application database access check failed', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
                    'container_name' => $containerName,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($delaySeconds);
            }
        }

        throw new \RuntimeException('Application could not connect to the database within '.$timeoutSeconds.' seconds.');
    }

    private function tearDownStack(SSHService $ssh, string $containerPath, bool $removeVolumes): void
    {
        $composeFile = escapeshellarg($containerPath.'/docker-compose.yml');
        $pathArg = escapeshellarg($containerPath);

        try {
            $exists = trim($ssh->exec("[ -f {$composeFile} ] && echo yes || echo no", 10));
            if ($exists !== 'yes') {
                return;
            }

            $volumeFlag = $removeVolumes ? '-v ' : '';
            @$ssh->exec(
                "cd {$pathArg} && docker compose -f docker-compose.yml down {$volumeFlag}--remove-orphans",
                self::DEPLOY_TIMEOUT
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to tear down existing compose stack before deploy', [
                'container_path' => $containerPath,
                'remove_volumes' => $removeVolumes,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function databaseSidecarIsReady(
        SSHService $ssh,
        string $containerPathArg,
        DatabaseTemplate $databaseTemplate,
        array $envVars
    ): bool {
        return match ($databaseTemplate->type) {
            'mysql', 'mariadb' => $this->mysqlSidecarIsReady($ssh, $containerPathArg, $envVars),
            'postgresql' => $this->postgresqlSidecarIsReady($ssh, $containerPathArg, $envVars),
            default => true,
        };
    }

    private function mysqlSidecarIsReady(SSHService $ssh, string $containerPathArg, array $envVars): bool
    {
        $password = escapeshellarg((string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''));
        $command = "cd {$containerPathArg} && docker compose exec -T -e MYSQL_PWD={$password} db mysqladmin ping -h localhost --silent";

        $ssh->exec($command, 20);

        return true;
    }

    private function postgresqlSidecarIsReady(SSHService $ssh, string $containerPathArg, array $envVars): bool
    {
        $user = escapeshellarg((string) ($envVars['POSTGRES_USER'] ?? $envVars['DB_USERNAME'] ?? 'appuser'));
        $password = escapeshellarg((string) ($envVars['POSTGRES_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''));
        $command = "cd {$containerPathArg} && docker compose exec -T -e PGPASSWORD={$password} db pg_isready -U {$user} -h localhost";

        $ssh->exec($command, 20);

        return true;
    }

    private function applicationDatabaseAccessReady(
        SSHService $ssh,
        string $containerArg,
        DatabaseTemplate $databaseTemplate,
        array $envVars
    ): bool {
        return match ($databaseTemplate->type) {
            'mysql', 'mariadb' => $this->mysqlApplicationAccessReady($ssh, $containerArg, $envVars),
            'postgresql' => $this->postgresqlApplicationAccessReady($ssh, $containerArg, $envVars),
            default => true,
        };
    }

    private function mysqlApplicationAccessReady(SSHService $ssh, string $containerArg, array $envVars): bool
    {
        $database = (string) ($envVars['DB_DATABASE'] ?? $envVars['MYSQL_DATABASE'] ?? 'appdb');
        $username = (string) ($envVars['DB_USERNAME'] ?? $envVars['MYSQL_USER'] ?? 'appuser');
        $password = (string) ($envVars['DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? '');

        $script = 'try { '
            .'$pdo = new PDO('
            .'"mysql:host=db;port=3306;dbname='.addslashes($database).'", '
            .'"'.addslashes($username).'", '
            .'"'.addslashes($password).'", '
            .'[PDO::ATTR_TIMEOUT => 5]'
            .'); '
            .'$pdo->query("SELECT 1"); '
            .'exit(0); '
            .'} catch (Throwable $e) { exit(1); }';

        $ssh->exec(
            'docker exec -u www-data '.$containerArg.' php -r '.escapeshellarg($script),
            20
        );

        return true;
    }

    private function postgresqlApplicationAccessReady(SSHService $ssh, string $containerArg, array $envVars): bool
    {
        $database = (string) ($envVars['DB_DATABASE'] ?? $envVars['POSTGRES_DB'] ?? 'appdb');
        $username = (string) ($envVars['DB_USERNAME'] ?? $envVars['POSTGRES_USER'] ?? 'appuser');
        $password = (string) ($envVars['DB_PASSWORD'] ?? $envVars['POSTGRES_PASSWORD'] ?? '');

        $script = 'try { '
            .'$pdo = new PDO('
            .'"pgsql:host=db;port=5432;dbname='.addslashes($database).'", '
            .'"'.addslashes($username).'", '
            .'"'.addslashes($password).'", '
            .'[PDO::ATTR_TIMEOUT => 5]'
            .'); '
            .'$pdo->query("SELECT 1"); '
            .'exit(0); '
            .'} catch (Throwable $e) { exit(1); }';

        $ssh->exec(
            'docker exec -u www-data '.$containerArg.' php -r '.escapeshellarg($script),
            20
        );

        return true;
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

    public function resolveTemplateDockerImage(object $template, ?string $selectedVersion = null): string
    {
        return $this->resolveDockerImage($template, $selectedVersion);
    }

    private function resolveDockerImage($template, ?string $selectedVersion = null): string
    {
        if ($this->runtimeImages->usesRuntimeImage($template)) {
            return $this->runtimeImages->resolveImageReference($template, $selectedVersion)['image'];
        }

        $dockerImage = (string) ($template->docker_image ?? '');

        if ($selectedVersion && $template->versions) {
            $versions = is_array($template->versions)
                ? $template->versions
                : json_decode($template->versions, true) ?? [];

            if (in_array($selectedVersion, $versions, true)) {
                $imageName = explode(':', $dockerImage)[0];

                return $imageName.':'.$selectedVersion;
            }
        }

        return $dockerImage;
    }

    private function resolveHostAppPath($template, string $containerName): ?string
    {
        if (! isset($template->volume_paths) || ! is_array($template->volume_paths)) {
            // Legacy template rows may miss volume_paths; still keep app path
            // for runtime templates that expect /app content.
            if (in_array($template->slug ?? '', ['laravel', 'php', 'nodejs', 'python', 'ruby'], true)) {
                return self::CONTAINER_BASE_PATH.'/'.$containerName.'/app';
            }

            return null;
        }

        if (! array_key_exists('app_data', $template->volume_paths)) {
            if (in_array($template->slug ?? '', ['laravel', 'php', 'nodejs', 'python', 'ruby'], true)) {
                return self::CONTAINER_BASE_PATH.'/'.$containerName.'/app';
            }

            return null;
        }

        return self::CONTAINER_BASE_PATH.'/'.$containerName.'/app';
    }

    private function resolveApplicationRuntime(SSHService $ssh, $template, ?string $hostAppPath): ?ApplicationRuntime
    {
        if (! $hostAppPath || ! $this->applicationRuntime->supportsTemplate($template->slug ?? null)) {
            return null;
        }

        return $this->applicationRuntime->detectFromHost(
            $ssh,
            $hostAppPath,
            (string) $template->slug,
            (int) ($template->default_port ?? 3000)
        );
    }

    public function refreshApplicationRuntimeCompose(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $service->loadMissing('product.containerTemplate');
        $template = $service->product?->containerTemplate;

        if (! $template || ! $this->applicationRuntime->supportsTemplate($template->slug)) {
            return '';
        }

        $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name);
        if (! $hostAppPath) {
            return '';
        }

        $runtime = $this->applicationRuntime->detectFromHost(
            $ssh,
            $hostAppPath,
            (string) $template->slug,
            (int) ($template->default_port ?? 3000)
        );

        $databaseTemplate = $this->resolveDatabaseTemplate($service, $template);
        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            $databaseTemplate,
            $deployment,
            $deployment->selected_version,
            $hostAppPath,
            $runtime
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deployment->update(['docker_compose_content' => $composeYaml]);
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');
        $ssh->exec("cd {$containerPath} && docker compose up -d", self::DEPLOY_TIMEOUT);

        return 'Application start command updated ('.$runtime->label.').';
    }

    public function refreshLaravelServeCompose(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $service->loadMissing('product.containerTemplate');
        $template = $service->product?->containerTemplate;

        if (($template->slug ?? null) !== 'laravel') {
            return '';
        }

        $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name);
        if (! $hostAppPath) {
            return '';
        }

        $resolver = app(LaravelProjectPathResolver::class);
        if (! $resolver->hasProject($ssh, $hostAppPath)) {
            return '';
        }

        $resolved = $resolver->persistResolvedPaths($service, $ssh, $deployment);
        $documentRoot = $resolved['document_root'] ?? $resolver->resolveDocumentRoot($ssh, $hostAppPath);

        $databaseTemplate = $this->resolveDatabaseTemplate($service, $template);
        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            $databaseTemplate,
            $deployment,
            $deployment->selected_version,
            $hostAppPath,
            null,
            $documentRoot
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deployment->update(['docker_compose_content' => $composeYaml]);
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');
        $ssh->exec("cd {$containerPath} && docker compose up -d", self::DEPLOY_TIMEOUT);
        $this->waitForContainerRunning($ssh, $deployment->container_name);

        return 'Laravel document root updated ('.$documentRoot.').';
    }

    private function syncApplicationSource(SSHService $ssh, Service $service, $template, string $hostAppPath): void
    {
        app(ContainerGitRepositoryService::class)->syncForDeploy($ssh, $service, $hostAppPath);
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
            \Log::debug('Container not found in docker inspect', ['container_name' => $containerName]);

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

        \Log::debug('Container status check', [
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
    private function generateCredentials(
        Service $service,
        ContainerDeployment $deployment,
        array $envVars,
        ?DatabaseTemplate $databaseTemplate = null
    ): array {
        $credentials = [
            'access_url' => $deployment->getAccessUrl(),
            'port' => $deployment->assigned_port,
            'container_name' => $deployment->container_name,
            'admin_username' => $envVars['WORDPRESS_ADMIN_USER'] ?? $envVars['ADMIN_USER'] ?? 'admin',
            'admin_email' => $envVars['WORDPRESS_ADMIN_EMAIL'] ?? $service->user->email,
        ];

        if ($databaseTemplate) {
            $credentials['database'] = $this->extractDatabaseCredentials($databaseTemplate, $envVars);
        } elseif (($service->product?->containerTemplate?->slug ?? '') === 'wordpress') {
            $credentials['database'] = [
                'host' => $envVars['WORDPRESS_DB_HOST'] ?? 'mysql:3306',
                'name' => $envVars['WORDPRESS_DB_NAME'] ?? 'wordpress',
                'username' => $envVars['WORDPRESS_DB_USER'] ?? 'wordpress',
                'password' => $envVars['WORDPRESS_DB_PASSWORD'] ?? '',
            ];
        }

        if (! empty($envVars['WORDPRESS_ADMIN_PASSWORD'])) {
            $credentials['admin_password'] = $envVars['WORDPRESS_ADMIN_PASSWORD'];
        } elseif (! empty($envVars['ADMIN_PASSWORD'])) {
            $credentials['admin_password'] = $envVars['ADMIN_PASSWORD'];
        }

        return $credentials;
    }

    private function resolveDatabaseTemplate(Service $service, $template): ?DatabaseTemplate
    {
        $databaseId = $service->service_meta['database_id'] ?? null;
        if ($databaseId) {
            return DatabaseTemplate::find($databaseId);
        }

        if (($template->slug ?? '') === 'static-site') {
            return null;
        }

        if ($this->templateEnvironment->templateDefinesDatabaseSidecar($template)) {
            return null;
        }

        // Container PHP/Laravel apps expect a SQL sidecar when checkout metadata is missing.
        if (in_array($template->slug ?? '', ['laravel', 'php'], true)) {
            return DatabaseTemplate::query()
                ->where('is_active', true)
                ->where('hosting_type', 'container')
                ->where('type', 'mysql')
                ->orderBy('order')
                ->first();
        }

        return null;
    }

    /**
     * @return array{database: string, username: string}
     */
    private function defaultDatabaseIdentifiers(Service $service): array
    {
        $serviceId = max(1, (int) $service->id);
        $userId = max(1, (int) $service->user_id);

        return [
            'database' => $this->sanitizeDatabaseIdentifier("s{$serviceId}_db", 64),
            'username' => $this->sanitizeDatabaseIdentifier("u{$userId}_s{$serviceId}", 32),
        ];
    }

    private function sanitizeDatabaseIdentifier(string $value, int $maxLength): string
    {
        $value = strtolower(preg_replace('/[^a-z0-9_]/', '', $value) ?? '');
        if ($value === '' || ! preg_match('/^[a-z]/', $value)) {
            $value = 't'.$value;
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * @param  list<string>  $keys
     */
    private function resolveDatabaseName(array $env, Service $service, array $keys): string
    {
        foreach ($keys as $key) {
            if (! empty($env[$key])) {
                return $this->sanitizeDatabaseIdentifier((string) $env[$key], 64);
            }
        }

        return $this->defaultDatabaseIdentifiers($service)['database'];
    }

    /**
     * @param  list<string>  $keys
     */
    private function resolveDatabaseUsername(array $env, Service $service, array $keys): string
    {
        foreach ($keys as $key) {
            if (! empty($env[$key])) {
                return $this->sanitizeDatabaseIdentifier((string) $env[$key], 32);
            }
        }

        return $this->defaultDatabaseIdentifiers($service)['username'];
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironmentVariables(DatabaseTemplate $databaseTemplate, array $env, Service $service): array
    {
        return match ($databaseTemplate->type) {
            'mysql', 'mariadb' => $this->mysqlEnvironmentVariables($env, $service),
            'postgresql' => $this->postgresqlEnvironmentVariables($env, $service),
            'mongodb' => $this->mongodbEnvironmentVariables($env, $service),
            'redis' => [
                'REDIS_HOST' => 'db',
                'REDIS_PORT' => '6379',
                'REDIS_URL' => 'redis://db:6379',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function mysqlEnvironmentVariables(array $env, Service $service): array
    {
        $dbName = $this->resolveDatabaseName($env, $service, ['MYSQL_DATABASE', 'DB_DATABASE']);
        $dbUser = $this->resolveDatabaseUsername($env, $service, ['MYSQL_USER', 'DB_USERNAME']);
        $dbPassword = (string) ($env['DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? Str::random(32));
        $rootPassword = (string) ($env['MYSQL_ROOT_PASSWORD'] ?? $dbPassword);

        return [
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
            'MYSQL_DATABASE' => $dbName,
            'MYSQL_USER' => $dbUser,
            'MYSQL_PASSWORD' => $dbPassword,
            'DB_HOST' => 'db',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_CONNECTION' => 'mysql',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function postgresqlEnvironmentVariables(array $env, Service $service): array
    {
        $dbName = $this->resolveDatabaseName($env, $service, ['POSTGRES_DB', 'DB_DATABASE']);
        $dbUser = $this->resolveDatabaseUsername($env, $service, ['POSTGRES_USER', 'DB_USERNAME']);
        $dbPassword = (string) ($env['POSTGRES_PASSWORD'] ?? $env['DB_PASSWORD'] ?? Str::random(32));

        return [
            'POSTGRES_PASSWORD' => $dbPassword,
            'POSTGRES_DB' => $dbName,
            'POSTGRES_USER' => $dbUser,
            'DB_HOST' => 'db',
            'DB_PORT' => '5432',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_CONNECTION' => 'pgsql',
            'DATABASE_URL' => sprintf(
                'postgresql://%s:%s@db:5432/%s',
                rawurlencode($dbUser),
                rawurlencode($dbPassword),
                rawurlencode($dbName)
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mongodbEnvironmentVariables(array $env, Service $service): array
    {
        $dbUser = $this->resolveDatabaseUsername($env, $service, ['MONGO_INITDB_ROOT_USERNAME', 'DB_USERNAME']);
        $dbPassword = (string) ($env['MONGO_INITDB_ROOT_PASSWORD'] ?? $env['DB_PASSWORD'] ?? Str::random(32));
        $dbName = $this->resolveDatabaseName($env, $service, ['MONGO_INITDB_DATABASE', 'DB_DATABASE']);

        return [
            'MONGO_INITDB_ROOT_USERNAME' => $dbUser,
            'MONGO_INITDB_ROOT_PASSWORD' => $dbPassword,
            'MONGO_INITDB_DATABASE' => $dbName,
            'MONGODB_URI' => sprintf(
                'mongodb://%s:%s@db:27017/%s',
                rawurlencode($dbUser),
                rawurlencode($dbPassword),
                rawurlencode($dbName)
            ),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extractDatabaseCredentials(DatabaseTemplate $databaseTemplate, array $envVars): array
    {
        return match ($databaseTemplate->type) {
            'mysql', 'mariadb' => [
                'type' => $databaseTemplate->type,
                'host' => $envVars['DB_HOST'] ?? 'db',
                'port' => $envVars['DB_PORT'] ?? '3306',
                'database' => $envVars['DB_DATABASE'] ?? $envVars['MYSQL_DATABASE'] ?? 'appdb',
                'username' => $envVars['DB_USERNAME'] ?? $envVars['MYSQL_USER'] ?? 'appuser',
                'password' => $envVars['DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? null,
                'root_password' => $envVars['MYSQL_ROOT_PASSWORD'] ?? null,
            ],
            'postgresql' => [
                'type' => 'postgresql',
                'host' => $envVars['DB_HOST'] ?? 'db',
                'port' => $envVars['DB_PORT'] ?? '5432',
                'database' => $envVars['DB_DATABASE'] ?? $envVars['POSTGRES_DB'] ?? 'appdb',
                'username' => $envVars['DB_USERNAME'] ?? $envVars['POSTGRES_USER'] ?? 'appuser',
                'password' => $envVars['DB_PASSWORD'] ?? $envVars['POSTGRES_PASSWORD'] ?? null,
            ],
            'mongodb' => [
                'type' => 'mongodb',
                'host' => 'db',
                'port' => '27017',
                'database' => $envVars['MONGO_INITDB_DATABASE'] ?? 'appdb',
                'username' => $envVars['MONGO_INITDB_ROOT_USERNAME'] ?? 'appuser',
                'password' => $envVars['MONGO_INITDB_ROOT_PASSWORD'] ?? null,
            ],
            'redis' => [
                'type' => 'redis',
                'host' => $envVars['REDIS_HOST'] ?? 'db',
                'port' => $envVars['REDIS_PORT'] ?? '6379',
                'url' => $envVars['REDIS_URL'] ?? 'redis://db:6379',
            ],
            default => [
                'type' => $databaseTemplate->type,
            ],
        };
    }

    /**
     * Validate node has required SSH credentials for container operations
     */
    public function validateNodeSSHCredentials(Node $node): void
    {
        if (! $node->ssh_username) {
            throw new \DomainException(
                "Container host '{$node->hostname}' is not configured: missing SSH username. ".
                'An administrator needs to configure SSH credentials for this node.'
            );
        }

        if (! $node->ssh_password && ! $node->da_login_key) {
            throw new \DomainException(
                "Container host '{$node->hostname}' is not configured: missing SSH authentication (no password or key). ".
                'An administrator needs to configure SSH credentials for this node.'
            );
        }
    }

    /**
     * Ensure docker-compose.yml file exists, re-uploading if necessary
     */
    public function ensureComposeFileExists(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $composeFile = $containerPath.'/docker-compose.yml';

        // Check if file exists
        try {
            $ssh->exec("test -f {$composeFile}");

            return; // File exists
        } catch (\Exception $e) {
            // File doesn't exist, re-upload it
        }

        // Re-upload docker-compose.yml from stored content
        if (! $deployment->docker_compose_content) {
            throw new \RuntimeException(
                'docker-compose.yml file missing and no backup content stored. '.
                'Container deployment is corrupted. Please contact support.'
            );
        }

        \Log::warning("Re-uploading docker-compose.yml for deployment {$deployment->id}");
        $ssh->upload($deployment->docker_compose_content, $composeFile);
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

    private function purgeBackupsForService(Service $service): void
    {
        try {
            app(ContainerBackupService::class)->purgeAllForService($service);
        } catch (\Throwable $e) {
            \Log::warning('Failed to purge container backups during termination', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function unbindAllDomainsForService(Service $service): void
    {
        $domains = ContainerDomain::query()
            ->whereHas('deployment', function ($query) use ($service) {
                $query->where('service_id', $service->id);
            })
            ->with('deployment.node')
            ->get();

        if ($domains->isEmpty()) {
            return;
        }

        $nginxService = app(NginxProxyService::class);

        foreach ($domains as $domain) {
            try {
                if ($domain->deployment?->node) {
                    $nginxService->unbind($domain);
                } else {
                    $domain->delete();
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to unbind container domain during termination', [
                    'service_id' => $service->id,
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $domain->delete();
                } catch (\Throwable $deleteError) {
                    \Log::warning('Failed to delete container domain record after unbind failure', [
                        'service_id' => $service->id,
                        'domain_id' => $domain->id,
                        'error' => $deleteError->getMessage(),
                    ]);
                }
            }
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

            $nginxService = new NginxProxyService;
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

    /**
     * @return array{cpu_limit?: float, memory_limit_mb?: int}
     */
    private function containerResourceLimitsForService(Service $service): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment');

        $included = $service->product?->getIncludedContainerLimits(
            $service->product?->containerTemplate,
            $service->containerDeployment
        ) ?? [];

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $resellerLimits = $meta['reseller_catalog_limits'] ?? [];

        $payload = [];

        if (! empty($resellerLimits['cpu'])) {
            $payload['cpu_limit'] = (float) $resellerLimits['cpu'];
        } elseif (! empty($included['cpu'])) {
            $payload['cpu_limit'] = (float) $included['cpu'];
        }

        if (! empty($resellerLimits['memory_mb'])) {
            $payload['memory_limit_mb'] = (int) $resellerLimits['memory_mb'];
        } elseif (! empty($included['memory_mb'])) {
            $payload['memory_limit_mb'] = (int) $included['memory_mb'];
        }

        return $payload;
    }
}
