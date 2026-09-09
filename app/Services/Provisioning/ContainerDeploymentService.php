<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\SSH\SSHService;
use App\Services\Terminal\ContainerDockerExecUserResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Production-grade container deployment service
 * Handles Docker Compose lifecycle management via SSH
 */
class ContainerDeploymentService
{
    public const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    /**
     * One bridge per container host. Per-compose default networks each take a
     * /16 from Docker IPAM and exhaust the host after a few dozen sites.
     */
    public const SHARED_DOCKER_NETWORK = 'talksasa-net';

    public const VITE_ALLOWED_HOSTS_ENV = '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS';

    private RuntimeImageProvisioner $runtimeImages;

    private ContainerAppDirectoryService $appDirectory;

    private ContainerTemplateEnvironmentService $templateEnvironment;

    private ContainerStackCommandService $stackCommands;

    private ContainerApplicationRuntimeService $applicationRuntime;

    private WordPressContainerHardeningService $wordpressHardening;

    private ContainerElasticResourceService $elasticResources;

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
        ?WordPressContainerHardeningService $wordpressHardening = null,
        ?ContainerElasticResourceService $elasticResources = null,
    ) {
        $this->runtimeImages = $runtimeImages ?? new RuntimeImageProvisioner;
        $this->appDirectory = $appDirectory ?? new ContainerAppDirectoryService;
        $this->templateEnvironment = $templateEnvironment ?? new ContainerTemplateEnvironmentService;
        $this->stackCommands = $stackCommands ?? new ContainerStackCommandService;
        $this->applicationRuntime = $applicationRuntime ?? new ContainerApplicationRuntimeService;
        $this->wordpressHardening = $wordpressHardening ?? new WordPressContainerHardeningService;
        $this->elasticResources = $elasticResources ?? new ContainerElasticResourceService;
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
        $operationLock = null;
        $nodeRedeployRollback = null;
        $nodeRedeployCutoverStarted = false;

        try {
            // Load relationships
            $service->load('product.containerTemplate', 'user', 'node');

            $template = $this->resolveContainerTemplate($service);
            if (! $template) {
                throw new \DomainException('Service must have a container template');
            }
            if (($template->slug ?? '') === 'nodejs') {
                $operationLock = Cache::lock(
                    app(ContainerNodeBuildService::class)->lockName($service),
                    (int) config('containers.node_build.operation_lock_seconds', 1800),
                );
                $operationLock->block(10);
            }

            \Log::info('Container deploy started', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'template_id' => $template->id,
                'template_slug' => $template->slug,
                'project_role' => $service->service_meta['project_role'] ?? null,
            ]);
            $this->recordDeploymentEvent($service, null, 'deploy_started', [
                'template_id' => $template->id,
                'template_slug' => $template->slug,
            ]);

            // Select node if not already set. Redeploys stay on the current host
            // unless that host is over live pressure or no longer has headroom.
            if (! $service->node_id) {
                $node = $this->selectNode($template, $service);
            } else {
                $node = $service->node;
                if ($node && $this->shouldRelocateOffHost($node, $service, $template)) {
                    $relocated = app(ContainerNodeEvacuationService::class)->relocateIfNeeded(
                        $service,
                        'node_capacity'
                    );
                    if ($relocated) {
                        $service->refresh();
                        $node = $service->node;
                    }
                }
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
            $replaceExistingContainers = $existingDeployment !== null
                && $existingDeployment->status !== 'terminated';
            if (($template->slug ?? '') === 'nodejs' && $options->isRedeploy && $existingDeployment) {
                $nodeRedeployRollback = [
                    'compose' => (string) ($existingDeployment->docker_compose_content ?? ''),
                    'deployment_status' => $existingDeployment->status,
                    'service_status' => $service->status,
                    'node_release' => data_get($service->service_meta, 'node_release'),
                ];
            }
            $envValues = $service->service_meta['env_values'] ?? [];
            if ($existingDeployment && is_array($existingDeployment->env_values)) {
                $envValues = array_merge($existingDeployment->env_values, $envValues);
            }
            $envValues = array_merge($envValues, $this->projectRoleLinkEnv($service));
            $envVars = [];

            // Validate a manual Node pin against the existing source before
            // changing deployment/service status or stopping the healthy stack.
            if (($template->slug ?? '') === 'nodejs' && $options->isRedeploy && $existingDeployment) {
                $versionSsh = SSHService::forNode($node);
                try {
                    $selectedVersion = $this->resolveNodeVersionForDeployment(
                        $service,
                        $existingDeployment,
                        $versionSsh,
                    );
                } finally {
                    $versionSsh->disconnect();
                }
            }

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
                        $lockedNode = Node::whereKey($node->id)->lockForUpdate()->firstOrFail();
                        $lockedNode->load([
                            'containerDeployments' => fn ($query) => $query
                                ->where('status', '!=', 'terminated')
                                ->with('service.product.containerTemplate'),
                        ]);
                        if (! $this->nodeHasCapacity($lockedNode, $service, $template)) {
                            throw new \DomainException(
                                "Container host '{$lockedNode->name}' no longer has safe reserved capacity. Retry to select another host."
                            );
                        }
                        if (! $service->node_id) {
                            $service->update(['node_id' => $lockedNode->id]);
                        }

                        // Always reuse the most recent deployment row for this service.
                        // Using an older row can violate unique(container_name) during redeploy.
                        $existingDeployment = ContainerDeployment::where('service_id', $service->id)
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->first();

                        $preferredPort = null;
                        $ignoreDeploymentIds = [];
                        if ($existingDeployment) {
                            $ignoreDeploymentIds[] = (int) $existingDeployment->id;
                            $existingPort = (int) ($existingDeployment->assigned_port ?? 0);
                            if ($existingPort > 0 && (int) $existingDeployment->node_id === (int) $lockedNode->id) {
                                $preferredPort = $existingPort;
                            }
                        }

                        $port = $this->assignPort($lockedNode, $preferredPort, $ignoreDeploymentIds);
                        $envVars = $this->buildEnvironmentVariables($template, $envValues, $service, $databaseTemplate, $port, $containerName);

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

                $hasDatabaseSidecar = $databaseTemplate !== null
                    || $this->templateEnvironment->templateDefinesDatabaseSidecar($template);

                if ($options->shouldResetDatabase($hasDatabaseSidecar)) {
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: true);
                    $databaseReset = true;
                    $this->recordDeploymentEvent($service, $deployment, 'database_volume_reset', [
                        'container_name' => $containerName,
                    ]);
                } elseif (($options->isRedeploy || $replaceExistingContainers) && ($template->slug ?? '') !== 'nodejs') {
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: false);
                }

                // Prepare application source on host path before starting compose.
                // Redeploys keep existing /app files; customers refresh code from the Git tab.
                if ($hostAppPath && ! $options->isRedeploy) {
                    $this->syncApplicationSource($ssh, $service, $template, $hostAppPath);
                } elseif ($hostAppPath) {
                    $ssh->mkdirp($hostAppPath);
                }

                if (($template->slug ?? '') === 'nodejs' && ! $options->isRedeploy) {
                    $selectedVersion = $this->resolveNodeVersionForDeployment(
                        $service,
                        $deployment,
                        $ssh,
                    );
                }

                $nodeTopology = null;
                if ($this->supportsSplitWebWorkloads($template->slug ?? null) && $hostAppPath) {
                    $service->refresh();
                    $meta = is_array($service->service_meta) ? $service->service_meta : [];
                    $nodeTopology = app(ContainerNodeWorkloadTopologyService::class)->resolve(
                        $service,
                        $ssh,
                        $hostAppPath,
                        is_string($meta['node_backend_root'] ?? null) ? $meta['node_backend_root'] : null,
                        is_string($meta['node_frontend_root'] ?? null) ? $meta['node_frontend_root'] : null,
                    );
                    if (($template->slug ?? '') === 'nodejs') {
                        $selectedVersion = $this->resolveSplitNodeVersion(
                            $service,
                            $deployment,
                            $template,
                            $nodeTopology,
                            $selectedVersion,
                        );
                    }
                    app(ContainerNodeWorkloadTopologyService::class)->persist($service, $nodeTopology);
                    $this->recordDeploymentEvent($service, $deployment, 'node_workload_topology_resolved', [
                        'topology' => $nodeTopology['topology'] ?? 'single',
                        'backend_root' => data_get($nodeTopology, 'backend.root'),
                        'frontend_root' => data_get($nodeTopology, 'frontend.root'),
                        'selection_source' => $nodeTopology['selection_source'] ?? 'stack',
                    ]);
                    if (($nodeTopology['topology'] ?? 'single') === 'split_web_api') {
                        $envVars['INTERNAL_API_URL'] ??= 'http://backend:'.ContainerNodeWorkloadTopologyService::BACKEND_PORT;
                        $envVars['BACKEND_URL'] ??= $envVars['INTERNAL_API_URL'];
                        $envVars['NEXT_PUBLIC_API_URL'] ??= '/api';
                        $envVars['VITE_API_URL'] ??= '/api';
                        $deployment->update(['env_values' => $envVars]);
                        $ssh->upload(NodeWebGatewayProxy::scriptContents(), NodeWebGatewayProxy::scriptPath($hostAppPath));
                        if (($nodeTopology['frontend_type'] ?? '') === 'vite-spa') {
                            $ssh->upload(NodeWebGatewayProxy::viteConfig(), NodeWebGatewayProxy::viteConfigPath($hostAppPath));
                        }
                    }
                }

                $applicationRuntime = $this->resolveApplicationRuntime($ssh, $template, $hostAppPath, $nodeTopology);
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
                    $laravelDocumentRoot,
                    nodeTopology: $nodeTopology,
                );
                $deployment->update(['docker_compose_content' => $composeYaml]);

                // Upload docker-compose.yml
                $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

                // Node releases are built once, before runtime startup. The app
                // container command only starts the already-validated release.
                if (($template->slug ?? '') === 'nodejs') {
                    app(ContainerNodeBuildService::class)->build(
                        $service->fresh(['product.containerTemplate']),
                        $deployment->fresh(),
                        $ssh,
                        forceRebuild: $options->isRedeploy,
                        operationAlreadyLocked: true,
                    );
                } elseif (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                    $this->stackCommands->buildSplitWebFrontend(
                        $deployment->fresh(),
                        $ssh,
                        (string) data_get($nodeTopology, 'frontend.root'),
                        forceRebuild: $options->isRedeploy,
                    );
                }
                if (($options->isRedeploy || $replaceExistingContainers) && ($template->slug ?? '') === 'nodejs') {
                    $nodeRedeployCutoverStarted = true;
                    $this->recordDeploymentEvent($service, $deployment, 'node_cutover_started', [
                        'topology' => $nodeTopology['topology'] ?? 'single',
                    ]);
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: false);
                }

                // Quiet convert / reset: wipe volumes after compose exists so named volumes
                // from prior failed attempts cannot keep a stale MySQL root password.
                if ($options->shouldResetDatabase($hasDatabaseSidecar)) {
                    $this->tearDownStack($ssh, $containerPath, removeVolumes: true);
                    $databaseReset = true;
                    $this->recordDeploymentEvent($service, $deployment, 'database_volume_reset_after_compose', [
                        'container_name' => $containerName,
                    ]);
                }

                if ($this->runtimeImages->usesRuntimeImage($template)) {
                    $this->runtimeImages->ensureImage($ssh, $template, $selectedVersion, $service, $deployment);
                }
                if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                    $this->ensureNodeWebSidecarImages($ssh, (string) ($nodeTopology['frontend_type'] ?? 'nextjs'));
                }

                if (($template->slug ?? '') === 'wordpress') {
                    $this->wordpressHardening->ensureUploadsIniFile($ssh, $containerName);
                }

                if (($template->slug ?? '') === 'static-site') {
                    app(StaticSiteDocrootService::class)->ensureNginxConfigFile($ssh, $containerName);
                }

                $availablePort = $this->ensurePublishedPortIsAvailable(
                    $ssh,
                    $node,
                    $deployment,
                    (int) $port,
                    $containerName,
                    $containerPath,
                );
                if ($availablePort !== (int) $port) {
                    $port = $availablePort;
                    $envVars['APP_PORT'] = (string) $port;
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
                        $laravelDocumentRoot,
                        nodeTopology: $nodeTopology,
                    );
                    $deployment->update([
                        'assigned_port' => $port,
                        'env_values' => $envVars,
                        'docker_compose_content' => $composeYaml,
                    ]);
                    $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');
                    $this->recordDeploymentEvent($service, $deployment, 'port_reassigned', [
                        'assigned_port' => $port,
                    ]);
                }

                // Deploy container
                $composeTimeout = $this->composeUpTimeoutSeconds($template);
                $this->recordDeploymentEvent($service, $deployment, 'compose_up_started', [
                    'container_name' => $containerName,
                    'timeout_seconds' => $composeTimeout,
                    'image' => $this->resolveDockerImage($template, $selectedVersion),
                ]);
                $deployment->touch();
                $this->composeUp(
                    $ssh,
                    $containerPath,
                    $this->runtimeImages->usesRuntimeImage($template),
                    timeoutSeconds: $composeTimeout,
                    containerName: $containerName,
                );

                // Host mount is the source of truth for /app; ensure placeholders after compose is up.
                // WordPress must stay empty so the official image can copy core. Static nginx must
                // stay empty too — the welcome page is later hoisted as a fake homepage.
                if ($hostAppPath && $this->appDirectory->shouldWriteDeployPlaceholder($template->slug ?? '')) {
                    $this->appDirectory->ensurePlaceholderState($ssh, $hostAppPath);
                }

                $this->appDirectory->normalizePermissions($ssh, $deployment);
                // PHP extensions only — Next sidecar switch must run AFTER the Laravel
                // container passes health (switching compose first left the stack unhealthy).
                $this->syncPhpExtensionsIfSupported($ssh, $service, $deployment);

                // Health behavior is template-configurable: strict templates fail on timeout,
                // relaxed templates continue for smoother redeploys while still logging warnings.
                $strictHealthCheck = $this->isStrictHealthCheckEnabled($template);
                $healthTimeoutSeconds = $this->healthCheckTimeoutSeconds($template);
                $this->recordDeploymentEvent($service, $deployment, 'health_check_started', [
                    'container_name' => $containerName,
                    'strict' => $strictHealthCheck,
                    'timeout_seconds' => $healthTimeoutSeconds,
                ]);
                $deployment->touch();
                try {
                    $this->waitForContainerHealth($ssh, $containerName, $healthTimeoutSeconds, $deployment);
                    if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                        $this->waitForNodeSplitStackReadiness($ssh, $deployment, $healthTimeoutSeconds);
                        $this->recordDeploymentEvent($service, $deployment, 'split_web_cutover_ready', [
                            'backend_root' => data_get($nodeTopology, 'backend.root'),
                            'frontend_root' => data_get($nodeTopology, 'frontend.root'),
                        ]);
                    } elseif (($template->slug ?? '') === 'nodejs') {
                        $this->waitForNodeApplicationReadiness($ssh, $deployment, $healthTimeoutSeconds);
                    }
                    if (($template->slug ?? '') === 'nodejs') {
                        app(ContainerNodeBuildService::class)->markHealthy($service, $deployment);
                    }
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

                if (($template->slug ?? '') !== 'nodejs') {
                    $this->stackCommands->executeSetupCommands(
                        $ssh,
                        $containerPath,
                        $containerName,
                        $template,
                        self::DEPLOY_TIMEOUT
                    );
                }

                // Build Next + switch to backend/frontend/edge after Laravel is healthy.
                try {
                    $this->installLaravelFrontendAfterDeploy($ssh, $service, $deployment->fresh());
                } catch (\Throwable $e) {
                    \Log::warning('Laravel Next sidecar setup after deploy failed', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
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

                if (($template->slug ?? '') === 'wordpress') {
                    // Domains first so wp core install gets the real public URL when available.
                    $this->reattachAndBindPrimaryDomains($service, $deployment);

                    if ($options->shouldInstallWordPressApplication((string) ($template->slug ?? ''))) {
                        try {
                            $installResult = app(WordPressAppInstallationService::class)->installIfNeeded(
                                $service->fresh(['product.containerTemplate', 'user', 'containerDeployment.node', 'containerDeployment.domains']),
                                $deployment->fresh(['node', 'domains']),
                                $ssh,
                                $envVars,
                            );
                            $this->recordDeploymentEvent($service, $deployment, 'wordpress_application_installed', [
                                'skipped' => $installResult['skipped'],
                                'message' => $installResult['message'],
                            ]);
                        } catch (\Throwable $installError) {
                            \Log::warning('WordPress auto-install failed', [
                                'service_id' => $service->id,
                                'error' => $installError->getMessage(),
                            ]);
                            $this->recordDeploymentEvent($service, $deployment, 'wordpress_application_install_failed', [
                                'error' => $installError->getMessage(),
                            ]);
                        }
                    }

                    $this->wordpressHardening->hardenDeployedStack(
                        $ssh,
                        $service->fresh(['product.containerTemplate', 'containerDeployment']),
                        $containerName,
                        $containerPath
                    );
                } elseif (($template->slug ?? '') === 'ollama') {
                    $this->reattachAndBindPrimaryDomains($service, $deployment);

                    try {
                        $pullResult = app(ContainerOllamaModelService::class)->pullIfNeeded(
                            $service->fresh(['product.containerTemplate', 'containerDeployment']),
                            $deployment->fresh(),
                            $ssh,
                            $containerPath,
                            $containerName,
                        );
                        $this->recordDeploymentEvent($service, $deployment, 'ollama_model_pulled', [
                            'skipped' => $pullResult['skipped'],
                            'model' => $pullResult['model'],
                            'message' => $pullResult['message'],
                        ]);
                    } catch (\Throwable $pullError) {
                        \Log::warning('Ollama model pull failed', [
                            'service_id' => $service->id,
                            'error' => $pullError->getMessage(),
                        ]);
                        $this->recordDeploymentEvent($service, $deployment, 'ollama_model_pull_failed', [
                            'error' => $pullError->getMessage(),
                        ]);
                    }
                } else {
                    // Ensure existing bound domains always follow the latest deployment
                    // row/port after redeploys, otherwise nginx may point to stale ports.
                    $this->reattachAndBindPrimaryDomains($service, $deployment);
                }

                if ($options->shouldInstallLaravelApplication((string) ($template->slug ?? ''))) {
                    try {
                        $installResult = app(LaravelAppInitializationService::class)->queueFreshInstallationIfNeeded(
                            $service->fresh(['product.containerTemplate', 'user', 'containerDeployment.node']),
                            $deployment->fresh(['node']),
                            $ssh,
                        );
                        $this->recordDeploymentEvent($service, $deployment, 'laravel_application_initialization_queued', [
                            'skipped' => $installResult['skipped'],
                            'message' => $installResult['message'],
                            'initialization_id' => $installResult['initialization_id'] ?? null,
                        ]);
                    } catch (\Throwable $installError) {
                        \Log::warning('Laravel auto-initialization queue failed', [
                            'service_id' => $service->id,
                            'error' => $installError->getMessage(),
                        ]);
                        $this->recordDeploymentEvent($service, $deployment, 'laravel_application_initialization_queue_failed', [
                            'error' => $installError->getMessage(),
                        ]);
                    }
                }

                if ($options->replaceApplication && ($template->slug ?? '') === 'php') {
                    try {
                        $hostAppPath = $containerPath.'/app';
                        $meta = is_array($service->service_meta) ? $service->service_meta : [];
                        $repoUrl = strtolower((string) ($meta['source_repo_url'] ?? ''));
                        $hostLooksLikeOspos = trim($ssh->exec(
                            'test -f '.escapeshellarg($hostAppPath.'/app/Config/OSPOS.php').' && echo yes || echo no',
                            10
                        )) === 'yes';
                        if (! $hostLooksLikeOspos && ! str_contains($repoUrl, 'opensourcepos/opensourcepos')) {
                            throw new \RuntimeException(
                                'Replace application files on PHP only installs Open Source POS when app/Config/OSPOS.php exists or the Git repo is opensourcepos/opensourcepos.'
                            );
                        }

                        $osposResult = app(PhpOsposAppInstaller::class)->installKeepingDatabase(
                            $service->fresh(['product.containerTemplate', 'user', 'containerDeployment.node']),
                            $deployment->fresh(['node']),
                            $ssh,
                        );
                        $this->recordDeploymentEvent($service, $deployment, 'ospos_application_installed', [
                            'message' => $osposResult['message'],
                            'database_reset' => $options->resetDatabase,
                        ]);
                    } catch (\Throwable $installError) {
                        \Log::warning('Open Source POS install after redeploy failed', [
                            'service_id' => $service->id,
                            'error' => $installError->getMessage(),
                        ]);
                        $this->recordDeploymentEvent($service, $deployment, 'ospos_application_install_failed', [
                            'error' => $installError->getMessage(),
                        ]);
                    }
                }

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

                // Notify user (skipped for admin quiet converts)
                if (! $options->quiet) {
                    app(NotificationService::class)->notifyServiceActivated($service->fresh());
                }

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
        } catch (\Throwable $e) {
            if (is_array($nodeRedeployRollback) && isset($existingDeployment, $node, $containerName)) {
                try {
                    $this->rollbackNodeRedeploy(
                        $service,
                        $existingDeployment,
                        $node,
                        $containerName,
                        $nodeRedeployRollback,
                        $nodeRedeployCutoverStarted,
                    );
                    $this->recordDeploymentEvent($service, $existingDeployment, 'node_redeploy_rolled_back', [
                        'error' => $e->getMessage(),
                        'cutover_started' => $nodeRedeployCutoverStarted,
                    ]);
                    throw $e;
                } catch (\Throwable $rollbackError) {
                    if ($rollbackError === $e) {
                        throw $e;
                    }
                    \Log::critical('Node redeploy rollback failed', [
                        'service_id' => $service->id,
                        'error' => $rollbackError->getMessage(),
                        'original_error' => $e->getMessage(),
                    ]);
                }
            }
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
        } finally {
            $operationLock?->release();
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

                // Park nginx first so visitors see a 503 page instead of the stock
                // "502 Bad Gateway / nginx/1.18.0" while the container is stopping.
                try {
                    app(NginxProxyService::class)->applySuspendedVhosts($service);
                } catch (\Throwable $e) {
                    \Log::warning('Parked nginx page was not applied before container stop', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
                $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml stop", self::DEPLOY_TIMEOUT);

                $deployment->update([
                    'status' => 'stopped',
                    'last_status_check_at' => now(),
                ]);

                $service->update(['status' => 'suspended']);
                app(ContainerCronService::class)->pauseForService($service);

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

            $template = $this->resolveContainerTemplate($service) ?? $service->product?->containerTemplate;
            if ($template && $this->shouldRelocateOffHost($deployment->node, $service, $template)) {
                app(ContainerNodeEvacuationService::class)->relocateIfNeeded($service, 'node_capacity');
                $service->refresh();
                $deployment = $service->containerDeployment;
                if (! $deployment || ! $deployment->node) {
                    throw new \DomainException('Container deployment not found');
                }
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

                // Force remove any leftover containers with explicit container_name.
                // docker rm -f is asynchronous: the daemon marks the container for
                // removal and compose up will fail if we start before that finishes.
                $removedNames = [];
                if (isset($composeData['services']) && is_array($composeData['services'])) {
                    foreach ($composeData['services'] as $serviceConfig) {
                        if (! is_array($serviceConfig) || ! isset($serviceConfig['container_name'])) {
                            continue;
                        }
                        $containerName = (string) $serviceConfig['container_name'];
                        $removedNames[] = $containerName;
                        @$ssh->exec('docker rm -f '.escapeshellarg($containerName).' 2>/dev/null || true', 60, false);
                        \Log::debug("Force removed container: {$containerName}");
                    }
                }

                // Stop and remove all containers/networks, then start fresh
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down --remove-orphans", self::DEPLOY_TIMEOUT);
                $this->waitForRemovingDockerContainers($ssh, $containerPath, $removedNames);

                $this->startComposeStack($ssh, $service, $deployment, recreate: false);

                $deployment->update([
                    'status' => 'running',
                    'last_status_check_at' => now(),
                ]);

                $service->update(['status' => 'active']);
                app(ContainerCronService::class)->resumeForService($service);

                try {
                    app(NginxProxyService::class)->restoreProxyVhosts($service->fresh());
                } catch (\Throwable $e) {
                    \Log::error('Container resumed but nginx vhosts were not restored to proxy', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

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
    public function terminate(Service $service, bool $notify = true): void
    {
        try {
            $deployment = $service->containerDeployment;

            if ($deployment && $deployment->node) {
                $node = $deployment->node;

                // Validate node has SSH credentials before attempting operation
                $this->validateNodeSSHCredentials($node);

                $ssh = SSHService::forNode($node);

                try {
                    $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

                    // Stop and remove containers
                    $ssh->exec(
                        'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml down -v',
                        self::DEPLOY_TIMEOUT
                    );

                    // Remove directory
                    $ssh->deleteDir($containerPath);

                    // Do not destroy the only recovery artifacts or mark termination complete
                    // until both the Docker workload and its host directory are confirmed absent.
                    $remaining = trim($ssh->exec(
                        'if docker ps -aq --filter '
                        .escapeshellarg('label=com.docker.compose.project='.$deployment->container_name)
                        .' | grep -q .'
                        .' || test -e '.escapeshellarg($containerPath)
                        .'; then echo present; else echo absent; fi',
                        30
                    ));
                    if ($remaining !== 'absent') {
                        throw new \RuntimeException('Container teardown could not be verified; backups were preserved.');
                    }

                    $this->unbindAllDomainsForService($service);
                    $this->purgeBackupsForService($service);

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
            } elseif ($deployment && $deployment->status !== 'terminated') {
                throw new \RuntimeException('Container node is missing; teardown cannot be verified.');
            }

            $service->update([
                'status' => 'terminated',
                'terminate_date' => now(),
            ]);
            app(ContainerCronService::class)->deleteForService($service);

            if ($notify) {
                app(NotificationService::class)->notifyServiceTerminated($service->fresh());
            }
        } catch (\Throwable $e) {
            \Log::error("Failed to terminate container for service {$service->id}: ".$e->getMessage());

            throw $e;
        }
    }

    /**
     * Restart a running container
     */
    public function restart(Service $service): void
    {
        $operationLock = null;
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
                $slug = strtolower((string) ($this->resolveContainerTemplate($service)?->slug ?? ''));
                if ($slug === 'nodejs') {
                    $operationLock = Cache::lock(
                        app(ContainerNodeBuildService::class)->lockName($service),
                        (int) config('containers.node_build.operation_lock_seconds', 1800),
                    );
                    $operationLock->block(10);
                }
                $hasDatabase = $this->resolveDatabaseTemplateForService($service) !== null;
                // Node/DirectAdmin apps often keep DB_HOST=localhost:/var/lib/mysql/mysql.sock.
                // Recreate the app with unique sidecar DNS so mysql2 uses TCP, not a missing unix socket.
                if ($hasDatabase || in_array($slug, ['laravel', 'php', 'nodejs', 'wordpress'], true)) {
                    $this->persistLaravelRuntimeDriversOnCompose($ssh, $deployment, [], $service);
                }
                if ($this->deploymentNeedsPhpHeal($deployment, $slug)) {
                    $this->alignLaravelDocumentRootOnCompose($ssh, $service, $deployment);
                    try {
                        app(PhpSidecarDatabaseRewriter::class)->applyForDeployment($ssh, $service, $deployment);
                    } catch (\Throwable $e) {
                        Log::warning('Could not rewrite PHP database config before restart', [
                            'service_id' => $service->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    try {
                        app(PhpLegacyMysqlShim::class)->installOnHost($ssh, $containerPath.'/app');
                    } catch (\Throwable $e) {
                        Log::warning('Could not install legacy mysql_* shim before restart', [
                            'service_id' => $service->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    try {
                        $fixer = app(PhpCodeIgniterPathFixer::class);
                        $fixer->applyOnHost($ssh, $containerPath.'/app');
                        $fixer->applyInContainer($ssh, $deployment);
                    } catch (\Throwable $e) {
                        Log::warning('Could not flatten CodeIgniter Paths.php require before restart', [
                            'service_id' => $service->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                if (in_array($slug, ['nodejs', 'python', 'ruby', 'go'], true)) {
                    // Git pull can leave files on disk while compose still runs the
                    // empty-app placeholder. Re-detect start command, then recreate.
                    $this->refreshApplicationRuntimeCompose($service, $deployment, $ssh);
                } else {
                    // Recreate the app service only. `docker compose restart` does not
                    // reload compose `environment` (SESSION_DRIVER=database stays in
                    // PHP-FPM). Recreating the whole stack also bounces MySQL → HTTP 2002.
                    $this->restartAppService($ssh, $deployment);
                }

                try {
                    app(NginxProxyService::class)->refreshBoundDomainVhosts($service, force: true);
                } catch (\Throwable $e) {
                    \Log::warning('Could not refresh nginx vhost after app restart', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $deployment->update([
                    'last_status_check_at' => now(),
                    'last_restart_at' => now(),
                ]);
                $deployment->increment('restart_attempts');

                $this->syncDatabaseCredentialsAfterStart($ssh, $service, $deployment, $containerPath);

                \Log::info("Container restarted for service {$service->id}");
            } finally {
                $ssh->disconnect();
            }
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: ".$e->getMessage());

            throw $e;
        } finally {
            $operationLock?->release();
        }
    }

    /**
     * Recreate only the PHP/app compose service — never the database sidecar.
     *
     * `docker compose restart` sends SIGTERM and keeps the old container env.
     * Laravel dotenv will not override SESSION_DRIVER already set from compose,
     * so .env cookie never takes effect until the app container is recreated.
     */
    public function composeRestartAppCommand(string $containerPath, string $appServiceName): string
    {
        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose -f docker-compose.yml up -d --no-deps --pull never --force-recreate '
            .escapeshellarg($appServiceName);
    }

    /**
     * Prefer recent app + sidecar output. `docker compose logs --tail=N` is
     * per-container, so a MySQL volume that has been initializing since day one
     * fills the UI with stale entrypoint lines and hides today's 500s.
     */
    public function composeLogsCommand(string $containerPath, int $lines): string
    {
        $tail = max(1, $lines);

        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose -f docker-compose.yml logs --no-color --since 6h --tail='.$tail;
    }

    /**
     * Write cookie/file drivers and this stack's unique DB hostname into compose
     * `environment` so PHP-FPM actually sees them after recreate. `.env` alone
     * is ignored when the variable is already set in the container env.
     *
     * Always read docker-compose.yml from disk. Using stale `docker_compose_content`
     * from the panel DB has rewritten the sidecar definition and bounced MySQL.
     *
     * @param  array<string, string>  $drivers
     */
    public function persistLaravelRuntimeDriversOnCompose(
        SSHService $ssh,
        ContainerDeployment $deployment,
        array $drivers = [],
        ?Service $service = null
    ): void {
        $drivers = $this->composeRuntimeEnvironmentOverrides($deployment, $drivers);
        $fromEnv = $this->stripMysqlUnixSocketEnv(
            array_merge(is_array($deployment->env_values) ? $deployment->env_values : [], $drivers)
        );
        $deployment->update(['env_values' => $fromEnv]);

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $yaml = '';
        try {
            $yaml = trim((string) $ssh->exec(
                'cat '.escapeshellarg($containerPath.'/docker-compose.yml'),
                15
            ));
        } catch (\Throwable) {
            $yaml = '';
        }
        if ($yaml === '') {
            $yaml = trim((string) ($deployment->docker_compose_content ?? ''));
        }
        if ($yaml === '') {
            return;
        }

        try {
            $patched = $this->patchComposeServiceEnvironment($yaml, $deployment->container_name, $drivers);
            $patched = $this->removeComposeEnvironmentKeys(
                $patched,
                $deployment->container_name,
                $this->mysqlUnixSocketEnvKeys()
            );
            $patched = $this->patchComposeSidecarNetworkAlias(
                $patched,
                $this->resolveMysqlComposeServiceName($fromEnv),
                $this->sidecarDnsHost((string) $deployment->container_name)
            );
        } catch (\Throwable $e) {
            Log::warning('Could not patch compose runtime drivers', [
                'container' => $deployment->container_name,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $dotenvService = $service ?? $deployment->service;
        $slug = strtolower((string) (
            $dotenvService instanceof Service
                ? ($dotenvService->effectiveContainerTemplate()?->slug
                    ?? $dotenvService->product?->containerTemplate?->slug
                    ?? '')
                : ''
        ));
        if ($slug === 'nodejs' && $dotenvService instanceof Service) {
            $fixer = app(NodeMysqlUnixSocketFixer::class);
            $unique = $this->sidecarDnsHost((string) $deployment->container_name);
            try {
                $fixer->apply($ssh, $dotenvService, $deployment, $unique);
            } catch (\Throwable $e) {
                Log::warning('Could not rewrite Node MySQL unix socket after restart pin', [
                    'container' => $deployment->container_name,
                    'error' => $e->getMessage(),
                ]);
            }
            try {
                $patched = $fixer->patchComposeCommand($patched, $deployment->container_name);
            } catch (\Throwable $e) {
                Log::warning('Could not inject Node MySQL TCP shim into compose', [
                    'container' => $deployment->container_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deployment->update(['docker_compose_content' => $patched]);
        $ssh->upload($patched, $containerPath.'/docker-compose.yml');
        $this->appDirectory->purgeLaravelConfigCacheOnHost(
            $ssh,
            $this->appDirectory->hostAppPath($deployment)
        );

        if ($dotenvService instanceof Service) {
            try {
                app(ContainerEnvironmentService::class)->syncDotEnvFile(
                    $ssh,
                    $dotenvService,
                    $deployment,
                    $drivers
                );
            } catch (\Throwable $e) {
                Log::warning('Could not sync .env after pinning database host', [
                    'container' => $deployment->container_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $unique = $this->sidecarDnsHost((string) $deployment->container_name);
        try {
            $this->attachSidecarNetworkAlias($ssh, $unique, $unique);
        } catch (\Throwable $e) {
            Log::warning('Could not attach unique sidecar DNS alias on talksasa-net', [
                'container' => $deployment->container_name,
                'alias' => $unique,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cookie/file drivers plus unique sidecar DNS — never the shared-network alias `db`.
     *
     * @param  array<string, string>  $drivers
     * @return array<string, string>
     */
    public function composeRuntimeEnvironmentOverrides(ContainerDeployment $deployment, array $drivers = []): array
    {
        $fromEnv = is_array($deployment->env_values) ? $deployment->env_values : [];
        $defaults = $this->envLooksLikeWordpress($fromEnv)
            ? []
            : [
                'SESSION_DRIVER' => 'file',
                'CACHE_STORE' => 'file',
                'CACHE_DRIVER' => 'file',
            ];
        $httpsOrigin = $this->httpsOriginForBoundDomain($deployment);
        if ($httpsOrigin !== null) {
            $appUrl = trim((string) ($fromEnv['APP_URL'] ?? ''));
            if ($appUrl === '' || $this->urlIsHttpOnHttpsOrigin($appUrl, $httpsOrigin)) {
                $defaults['APP_URL'] = $httpsOrigin;
            }
            $assetUrl = trim((string) ($fromEnv['ASSET_URL'] ?? ''));
            if ($assetUrl === '' || $this->urlIsHttpOnHttpsOrigin($assetUrl, $httpsOrigin)) {
                $defaults['ASSET_URL'] = $httpsOrigin;
            }
        }
        $connection = strtolower((string) ($fromEnv['DB_CONNECTION'] ?? ''));
        $url = strtolower((string) ($fromEnv['DATABASE_URL'] ?? ''));
        $databaseType = str_starts_with($url, 'postgres') || in_array($connection, ['pgsql', 'postgresql'], true)
            ? 'postgresql'
            : 'mysql';
        $pinned = $this->pinApplicationDatabaseHost(
            array_merge($fromEnv, $defaults, $drivers),
            (string) $deployment->container_name,
            $databaseType
        );

        $fromDb = [];
        foreach ([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_HOST', 'DATABASE_URL',
            'WORDPRESS_DB_HOST', 'WORDPRESS_DB_NAME', 'WORDPRESS_DB_USER', 'WORDPRESS_DB_PASSWORD',
            'POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD', 'TALKSASA_DB_DNS',
            'SESSION_DRIVER', 'CACHE_STORE', 'CACHE_DRIVER', 'APP_URL', 'ASSET_URL',
        ] as $key) {
            if (isset($pinned[$key]) && (string) $pinned[$key] !== '') {
                $fromDb[$key] = (string) $pinned[$key];
            }
        }

        return array_merge($defaults, $fromDb, $drivers);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    public function patchComposeServiceEnvironment(string $yaml, string $serviceName, array $overrides): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = $this->resolveComposeAppServiceKey($compose, $serviceName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $environment = $compose['services'][$key]['environment'] ?? [];
        $compose['services'][$key]['environment'] = $this->mergeComposeEnvironment(
            is_array($environment) ? $environment : [],
            $overrides
        );

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * container_name is not a DNS record on the shared talksasa-net overlay.
     * Laravel sidecars already set networks.default.aliases; WordPress mysql did not.
     */
    public function patchComposeSidecarNetworkAlias(string $yaml, string $serviceKey, string $alias): string
    {
        $alias = trim($alias);
        $serviceKey = trim($serviceKey);
        if ($alias === '' || $serviceKey === '') {
            return $yaml;
        }

        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'][$serviceKey] ?? null)) {
            return $yaml;
        }

        $networks = $compose['services'][$serviceKey]['networks'] ?? ['default' => []];
        if (is_array($networks) && array_is_list($networks)) {
            $mapped = [];
            foreach ($networks as $name) {
                if (is_string($name) && $name !== '') {
                    $mapped[$name] = [];
                }
            }
            $networks = $mapped === [] ? ['default' => []] : $mapped;
        }
        if (! is_array($networks)) {
            $networks = ['default' => []];
        }
        if (! isset($networks['default']) || ! is_array($networks['default'])) {
            $networks['default'] = [];
        }

        $aliases = $networks['default']['aliases'] ?? [];
        if (! is_array($aliases)) {
            $aliases = [];
        }
        if (! in_array($alias, $aliases, true)) {
            $aliases[] = $alias;
        }
        $networks['default']['aliases'] = array_values($aliases);
        $compose['services'][$serviceKey]['networks'] = $networks;

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * Re-attach the sidecar on talksasa-net with a unique DNS alias without
     * skip-grant-tables. Volume stays mounted.
     */
    public function attachSidecarNetworkAlias(SSHService $ssh, string $sidecarContainerName, string $alias): void
    {
        $sidecarContainerName = trim($sidecarContainerName);
        $alias = trim($alias);
        if ($sidecarContainerName === '' || $alias === '') {
            return;
        }

        $net = escapeshellarg(self::SHARED_DOCKER_NETWORK);
        $name = escapeshellarg($sidecarContainerName);
        $aliasArg = escapeshellarg($alias);

        $ssh->exec("docker network disconnect {$net} {$name} 2>/dev/null || true", 15);
        $ssh->exec("docker network connect --alias {$aliasArg} {$net} {$name}", 20);
    }

    /**
     * DirectAdmin Laravel imports often leave talksasa-php-server on /app while
     * public/index.php lives in /app/public — nginx then 403s directory index.
     */
    public function patchComposePhpDocumentRoot(string $yaml, string $containerName, string $documentRoot): string
    {
        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = $this->resolveComposeAppServiceKey($compose, $containerName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $command = $compose['services'][$key]['command'] ?? null;
        if (is_string($command)) {
            $parts = preg_split('/\s+/', trim($command)) ?: [];
            if (($parts[0] ?? null) === 'talksasa-php-server' && isset($parts[2])) {
                $parts[2] = $documentRoot;
                $compose['services'][$key]['command'] = $parts;

                return Yaml::dump($compose, 10, 2);
            }

            return $yaml;
        }

        if (is_array($command)
            && ($command[0] ?? null) === 'talksasa-php-server'
            && isset($command[2])) {
            $command[2] = $documentRoot;
            $compose['services'][$key]['command'] = $command;

            return Yaml::dump($compose, 10, 2);
        }

        return $yaml;
    }

    public function alignLaravelDocumentRootOnCompose(SSHService $ssh, Service $service, ContainerDeployment $deployment): void
    {
        $slug = strtolower((string) ($this->resolveContainerTemplate($service)?->slug ?? ''));
        if (! $this->deploymentNeedsPhpHeal($deployment, $slug)) {
            return;
        }

        $hostAppPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $resolver = app(LaravelProjectPathResolver::class);
        $documentRoot = $slug === 'laravel'
            ? $resolver->resolveDocumentRoot($ssh, $hostAppPath)
            : $this->phpDocumentRootOnHost($ssh, $hostAppPath);
        if ($documentRoot === '' || $documentRoot === '/app') {
            $found = null;
            foreach ($resolver->webRootRelativeCandidates() as $web) {
                $index = $hostAppPath.'/'.$web.'/index.php';
                try {
                    $hit = trim($ssh->exec(
                        'test -f '.escapeshellarg($index)
                        .' && grep -Eq '.escapeshellarg('vendor/autoload.php|Config/Paths.php').' '.escapeshellarg($index)
                        .' && echo yes || echo no',
                        15
                    ));
                } catch (\Throwable) {
                    continue;
                }
                if ($hit === 'yes') {
                    $found = '/app/'.$web;
                    break;
                }
            }
            if ($found !== null) {
                $documentRoot = $found;
            } elseif ($documentRoot !== '/app') {
                return;
            }
        }

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        try {
            $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($containerPath.'/docker-compose.yml'), 15));
        } catch (\Throwable) {
            return;
        }
        if ($yaml === '') {
            return;
        }

        $patched = $this->patchComposePhpDocumentRoot($yaml, $deployment->container_name, $documentRoot);
        if ($patched === $yaml) {
            return;
        }

        $deployment->update(['docker_compose_content' => $patched]);
        $ssh->upload($patched, $containerPath.'/docker-compose.yml');
        app(LaravelProjectPathResolver::class)->persistResolvedPaths($service, $ssh, $deployment);
    }

    /**
     * @param  array<string, mixed>  $compose
     */
    public function resolveComposeAppServiceKey(array $compose, string $containerName): ?string
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $cname = (string) ($service['container_name'] ?? $name);
            if ($cname === $containerName || (string) $name === $containerName) {
                return (string) $name;
            }
        }

        foreach (array_keys($services) as $name) {
            $name = (string) $name;
            if (in_array($name, ['db', 'mysql', 'mariadb', 'postgres', 'postgresql', 'redis', 'cache', 'mail'], true)) {
                continue;
            }
            if (str_ends_with($name, '-db') || str_ends_with($name, '_db')) {
                continue;
            }

            return $name;
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $environment
     * @param  array<string, string>  $overrides
     * @return array<int|string, mixed>
     */
    public function mergeComposeEnvironment(array $environment, array $overrides): array
    {
        if ($environment === []) {
            return $overrides;
        }

        $isList = array_is_list($environment);
        if ($isList) {
            $seen = [];
            $out = [];
            foreach ($environment as $item) {
                if (! is_string($item)) {
                    $out[] = $item;

                    continue;
                }
                $eq = strpos($item, '=');
                $key = $eq === false ? $item : substr($item, 0, $eq);
                if (array_key_exists($key, $overrides)) {
                    $out[] = $key.'='.$overrides[$key];
                    $seen[$key] = true;
                } else {
                    $out[] = $item;
                }
            }
            foreach ($overrides as $key => $value) {
                if (! isset($seen[$key])) {
                    $out[] = $key.'='.$value;
                }
            }

            return $out;
        }

        return array_merge($environment, $overrides);
    }

    /**
     * Drop DirectAdmin unix-socket keys so mysql2/mysqli cannot keep using /var/lib/mysql/mysql.sock.
     *
     * @param  list<string>  $keys
     */
    public function removeComposeEnvironmentKeys(string $yaml, string $serviceName, array $keys): string
    {
        if ($keys === []) {
            return $yaml;
        }

        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = $this->resolveComposeAppServiceKey($compose, $serviceName);
        if ($key === null || ! is_array($compose['services'][$key] ?? null)) {
            return $yaml;
        }

        $environment = $compose['services'][$key]['environment'] ?? [];
        $compose['services'][$key]['environment'] = $this->stripComposeEnvironmentKeys(
            is_array($environment) ? $environment : [],
            $keys
        );

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * @param  array<int|string, mixed>  $environment
     * @param  list<string>  $keys
     * @return array<int|string, mixed>
     */
    public function stripComposeEnvironmentKeys(array $environment, array $keys): array
    {
        $remove = array_fill_keys($keys, true);
        if ($environment === [] || $remove === []) {
            return $environment;
        }

        if (array_is_list($environment)) {
            return array_values(array_filter($environment, function ($item) use ($remove) {
                if (! is_string($item)) {
                    return true;
                }
                $eq = strpos($item, '=');
                $name = $eq === false ? $item : substr($item, 0, $eq);

                return ! isset($remove[$name]);
            }));
        }

        foreach ($keys as $key) {
            unset($environment[$key]);
        }

        return $environment;
    }

    public function restartAppService(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $serviceName = $deployment->container_name;
        try {
            $yaml = trim((string) $ssh->exec(
                'cat '.escapeshellarg($containerPath.'/docker-compose.yml'),
                15
            ));
            if ($yaml !== '') {
                $compose = Yaml::parse($yaml);
                if (is_array($compose)) {
                    $key = $this->resolveComposeAppServiceKey($compose, $deployment->container_name);
                    if (is_string($key) && $key !== '') {
                        $serviceName = $key;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $ssh->exec($this->composeRestartAppCommand($containerPath, $serviceName), self::DEPLOY_TIMEOUT);
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
                    $this->composeLogsCommand($containerPath, $lines),
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
    private function selectNode($template = null, ?Service $service = null, ?int $exceptNodeId = null): Node
    {
        $query = Node::where('type', 'container_host')
            ->where('is_active', true)
            ->where('status', 'online')
            ->with([
                'containerDeployments' => fn ($query) => $query
                    ->where('status', '!=', 'terminated')
                    ->with('service.product.containerTemplate'),
            ]);

        if ($exceptNodeId) {
            $query->where('id', '!=', $exceptNodeId);
        }

        $nodes = $query->get();
        if ($nodes->isEmpty()) {
            throw new \DomainException('No available container host nodes');
        }

        $capacity = app(ContainerNodeCapacityService::class);
        $nodes = $nodes
            ->sortBy([
                fn (Node $node) => $capacity->evaluate($node)['pressure_percent'],
                fn (Node $node) => (int) $node->container_count,
                fn (Node $node) => (int) $node->id,
            ])
            ->values();

        if (! $template) {
            return $nodes->first();
        }

        $node = $nodes->first(fn (Node $node) => $this->nodeHasCapacity($node, $service, $template));

        if (! $node) {
            $request = $this->placementRequest($service, $template);
            $reasons = $nodes
                ->map(fn (Node $candidate) => $this->capacityRejectionReason($candidate, $service, $template))
                ->filter()
                ->take(3)
                ->implode(' ');

            \Log::warning('Container placement rejected: no host has live capacity', [
                'service_id' => $service?->id,
                'template' => $template->slug ?? null,
                'request' => $request,
                'reasons' => $reasons,
            ]);

            throw new \DomainException(
                'No container host has enough available resources for this template'
                .($reasons !== '' ? '. '.$reasons : '')
                .sprintf(
                    ' Requested %.2f CPU, %d MB RAM, %.1f GB disk (container footprint, not sold plan).',
                    $request['cpu'],
                    $request['memory_mb'],
                    $request['disk_gb']
                )
            );
        }

        return $node;
    }

    /**
     * Confirm a container host can take this service before a long export or deploy.
     */
    public function assertHostHasCapacity(Service $service): Node
    {
        $template = $this->resolveContainerTemplate($service);
        if (! $template) {
            throw new \DomainException('Service must have a container template before a host can be selected.');
        }

        return $this->selectNode($template, $service);
    }

    public function selectLeastLoadedHost($template = null, ?Service $service = null, ?int $exceptNodeId = null): Node
    {
        return $this->selectNode($template, $service, $exceptNodeId);
    }

    public function hostCanAccept(Node $node, ?Service $service, object $template): bool
    {
        $node->load([
            'containerDeployments' => fn ($query) => $query
                ->where('status', '!=', 'terminated')
                ->with('service.product.containerTemplate'),
        ]);

        return $this->nodeHasCapacity($node, $service, $template);
    }

    public function shouldRelocateOffHost(Node $node, Service $service, object $template): bool
    {
        if ($node->type !== 'container_host' || ! $node->is_active || $node->status !== 'online') {
            return true;
        }

        if (app(ContainerNodeCapacityService::class)->needsScaleOut($node)) {
            return true;
        }

        return ! $this->hostCanAccept($node, $service, $template);
    }

    /**
     * Live host pressure plus the container that will actually start.
     * Sold plan CPU/RAM/disk are elastic (intentionally oversold) and must not block placement.
     */
    private function nodeHasCapacity(Node $node, ?Service $service, object $template): bool
    {
        return $this->capacityRejectionReason($node, $service, $template) === null;
    }

    /**
     * @return array{cpu: float, memory_mb: int, disk_gb: float}
     */
    private function placementRequest(?Service $service, object $template): array
    {
        $included = $service?->product?->getIncludedContainerLimits($template) ?? [
            'cpu' => (float) ($template->required_cpu_cores ?? 0),
            'memory_mb' => (int) ($template->required_ram_mb ?? 0),
            'disk_gb' => (float) ($template->required_storage_gb ?? 0),
        ];

        $cpu = (float) ($included['cpu'] ?? 0);
        $memoryMb = (int) ($included['memory_mb'] ?? 0);
        $diskGb = (float) ($included['disk_gb'] ?? 0);

        if ($service) {
            $docker = $this->containerResourceLimitsForService($service);
            if (isset($docker['cpu_limit'])) {
                $cpu = (float) $docker['cpu_limit'];
            }
            if (isset($docker['memory_limit_mb'])) {
                $memoryMb = (int) $docker['memory_limit_mb'];
            }

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $share = (float) ($meta['resource_share']['cpu'] ?? $meta['resource_share']['memory'] ?? 1);
            if ($share > 0 && $share < 1) {
                $diskGb = round($diskGb * $share, 2);
            }
        }

        return [
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'disk_gb' => $diskGb,
        ];
    }

    private function capacityRejectionReason(Node $node, ?Service $service, object $template): ?string
    {
        $requested = $this->placementRequest($service, $template);
        $reservedStorageGb = 0.0;

        foreach ($node->containerDeployments as $deployment) {
            // A redeploy replaces its reservation instead of consuming it twice.
            if ($service && (int) $deployment->service_id === (int) $service->id) {
                continue;
            }

            $limits = $deployment->service?->product?->getIncludedContainerLimits(
                $deployment->service?->product?->containerTemplate,
                $deployment
            ) ?? [
                'cpu' => (float) ($deployment->cpu_limit ?? 0),
                'memory_mb' => (int) ($deployment->memory_limit_mb ?? 0),
                'disk_gb' => 0,
            ];
            $reservedStorageGb += (float) ($limits['disk_gb'] ?? 0);
        }

        $ramHeadroom = max(0, min(50, (int) config('containers.elastic_resources.node_ram_headroom_percent', 20)));
        $cpuHeadroom = max(0, min(50, (int) config('containers.elastic_resources.node_cpu_headroom_percent', 10)));
        $storageHeadroom = max(0, min(50, (int) config('containers.elastic_resources.node_storage_headroom_percent', 10)));
        $ramCapacity = (float) $node->ram_gb * (1 - $ramHeadroom / 100);
        $storageCapacity = (float) $node->storage_gb * (1 - $storageHeadroom / 100);

        $liveCpuPercent = max(0.0, min(100.0, (float) $node->cpu_used));
        $usedRamGb = (float) $node->ram_used_gb;
        $usedStorageGb = (float) $node->storage_used_gb;
        $label = $node->name ?: $node->hostname;

        // Live CPU is elastic: extra DA sites share a package and bill overage.
        // A 100% reading on the only host must not freeze converts. RAM/disk remain hard.
        if ($liveCpuPercent > (100 - $cpuHeadroom)) {
            \Log::warning('Placing a container on a CPU-hot host; usage above the package bills as overage', [
                'node_id' => $node->id,
                'node' => $label,
                'live_cpu_percent' => $liveCpuPercent,
                'service_id' => $service?->id,
            ]);
        }

        $requestedRamGb = (float) $requested['memory_mb'] / 1024;
        if (($usedRamGb + $requestedRamGb) > $ramCapacity) {
            return sprintf(
                '%s: RAM %.1f GB used + %.1f GB request exceeds %.1f GB with headroom.',
                $label,
                $usedRamGb,
                $requestedRamGb,
                $ramCapacity
            );
        }

        // Sold disk is oversubscribed like CPU: extra Application Hosting sites
        // on one package bill combined usage above the plan as metric overage.
        // Placement only refuses when live disk is actually full.
        $diskNeed = $usedStorageGb + (float) $requested['disk_gb'];
        if ($diskNeed > $storageCapacity) {
            return sprintf(
                '%s: live disk %.1f GB + %.1f GB request exceeds %.1f GB with headroom (sold disk %.1f GB).',
                $label,
                $usedStorageGb,
                $requested['disk_gb'],
                $storageCapacity,
                $reservedStorageGb
            );
        }

        return null;
    }

    /**
     * Find and assign an available port on this host.
     *
     * @param  list<int>  $ignoreDeploymentIds  Rows being replaced (reuse their port).
     * @param  list<int>  $alsoUsedPorts  Host ports known to be bound outside the DB map.
     */
    public function assignPort(
        Node $node,
        ?int $preferredPort = null,
        array $ignoreDeploymentIds = [],
        array $alsoUsedPorts = [],
    ): int {
        $usedQuery = ContainerDeployment::where('node_id', $node->id)
            ->whereNotNull('assigned_port')
            ->lockForUpdate();

        $ignoreDeploymentIds = array_values(array_filter(array_map('intval', $ignoreDeploymentIds)));
        if ($ignoreDeploymentIds !== []) {
            $usedQuery->whereNotIn('id', $ignoreDeploymentIds);
        }

        $usedPorts = $usedQuery
            ->pluck('assigned_port')
            ->map(fn ($port) => (int) $port)
            ->merge(array_map('intval', $alsoUsedPorts))
            ->unique()
            ->values()
            ->all();

        if ($preferredPort !== null
            && $preferredPort >= self::PORT_RANGE_START
            && $preferredPort <= self::PORT_RANGE_END
            && ! in_array($preferredPort, $usedPorts, true)
        ) {
            return $preferredPort;
        }

        for ($port = self::PORT_RANGE_START; $port <= self::PORT_RANGE_END; $port++) {
            if (! in_array($port, $usedPorts, true)) {
                return $port;
            }
        }

        throw new \DomainException('No available ports in range '.self::PORT_RANGE_START.'-'.self::PORT_RANGE_END);
    }

    /**
     * Build complete environment variables including system vars and database connection
     */
    private function buildEnvironmentVariables($template, array $userValues, Service $service, ?DatabaseTemplate $databaseTemplate = null, ?int $port = null, ?string $appContainerName = null): array
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

        if (in_array($template->slug ?? '', ['nodejs', 'ruby', 'go'], true)) {
            $env['PORT'] = (string) ($template->default_port ?? ($template->slug === 'go' ? 8080 : 3000));
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
            $env = array_merge($env, $this->databaseEnvironmentVariables($databaseTemplate, $env, $service, $appContainerName));
        }

        $prepared = $this->templateEnvironment->prepare($template, $env, $service, $port);
        if (($template->slug ?? '') === 'nodejs') {
            unset($prepared['NPM_CONFIG_PRODUCTION'], $prepared['npm_config_production']);
        }

        return $prepared;
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
            'container_name' => $appServiceName.'-db',
            'restart' => 'always',
            'mem_limit' => '512M',
            'environment' => $dbEnv ?: null,
            'volumes' => ["db_data:{$mountPath}"],
            'networks' => [
                'default' => [
                    'aliases' => [$appServiceName.'-db'],
                ],
            ],
        ]);

        $compose['volumes']['db_data'] = null;
        $compose['services'][$appServiceName]['depends_on'] = ['db'];
    }

    /**
     * nginx + php-fpm start command (replaces php -S / artisan serve).
     *
     * @return list<string>
     */
    public static function phpProductionServerCommand(int $port, string $documentRoot): array
    {
        return ['talksasa-php-server', (string) $port, $documentRoot];
    }

    /**
     * Official agent images need an explicit gateway command so the published
     * port is reachable (Hermes dashboard / OpenClaw Control UI).
     *
     * @return list<string>|null
     */
    public static function imageGatewayCommand(?string $slug): ?array
    {
        return match ($slug) {
            'hermes' => ['gateway', 'run'],
            'openclaw' => ['node', 'dist/index.js', 'gateway', '--bind', 'lan', '--port', '18789'],
            'chatwoot' => ['bundle', 'exec', 'rails', 's', '-p', '3000', '-b', '0.0.0.0'],
            'erpnext' => ['nginx-entrypoint.sh'],
            default => null,
        };
    }

    /**
     * Render docker-compose.yml from template with optional database sidecar
     */
    private function renderCompose(
        $template,
        string $containerName,
        int $port,
        array $envVars,
        ?DatabaseTemplate $databaseTemplate = null,
        ?ContainerDeployment $deployment = null,
        ?string $selectedVersion = null,
        ?string $hostAppPath = null,
        ?ApplicationRuntime $applicationRuntime = null,
        ?string $laravelDocumentRoot = null,
        bool $serveNextFrontend = false,
        string $nextFrontendRelativeDir = 'frontend',
        int $laravelApiPort = 8001,
        ?array $nodeTopology = null,
    ): string {
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
                    'restart' => $deployment?->restart_policy ?? 'always',
                    'environment' => $envVars,
                    'ports' => ["{$port}:".$template->default_port],
                    'mem_limit' => $memoryLimitStr,
                    'cpus' => (float) $cpuLimit,
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
                    'name' => self::SHARED_DOCKER_NETWORK,
                    'external' => true,
                ],
            ],
        ];

        // Talksasa runtime images ship Composer, extensions, and entrypoint ownership fixes.
        if ($this->runtimeImages->usesRuntimeImage($template)) {
            $internalPort = (int) ($template->default_port ?: (($template->slug ?? null) === 'laravel' ? 8000 : 8080));
            $compose['services'][$containerName]['pull_policy'] = 'never';
            $compose['services'][$containerName]['user'] = 'www-data';
            $compose['services'][$containerName]['working_dir'] = '/app';

            if (($template->slug ?? null) === 'laravel') {
                $documentRoot = $laravelDocumentRoot ?: '/app/public';

                if ($serveNextFrontend) {
                    // Backend listens internally; public traffic hits the edge sidecar.
                    $compose['services'][$containerName]['command'] = LaravelNextGatewayProxy::backendComposeCommand(
                        $documentRoot,
                        LaravelNextGatewayProxy::BACKEND_PORT
                    );
                } else {
                    $compose['services'][$containerName]['command'] = $this->phpProductionServerCommand(
                        $internalPort,
                        $documentRoot
                    );
                }
            } else {
                $compose['services'][$containerName]['command'] = $this->phpProductionServerCommand(
                    $internalPort,
                    $laravelDocumentRoot ?: '/app'
                );
            }
        }

        $imageGatewayCommand = self::imageGatewayCommand($template->slug ?? null);
        if ($imageGatewayCommand !== null) {
            $compose['services'][$containerName]['command'] = $imageGatewayCommand;
        }

        if ($this->applicationRuntime->supportsTemplate($template->slug ?? null)) {
            $runtime = $applicationRuntime ?? $this->applicationRuntime->fallbackRuntime(
                (string) $template->slug,
                (int) ($template->default_port ?? 3000)
            );
            $compose['services'][$containerName]['working_dir'] = $runtime->containerWorkdir;
            $compose['services'][$containerName]['command'] = $runtime->command;

            if ($runtime->source === 'vite') {
                $allowedHosts = $this->viteAllowedHosts($deployment, $envVars);
                if ($allowedHosts !== '' && ! isset($envVars[self::VITE_ALLOWED_HOSTS_ENV])) {
                    $compose['services'][$containerName]['environment'][self::VITE_ALLOWED_HOSTS_ENV] = $allowedHosts;
                }
            }
        }

        // Add volumes
        if ($template->volume_paths) {
            $compose['services'][$containerName]['volumes'] = [];
            $compose['volumes'] = [];
            $bindMountTargets = $this->hostBindMountTargets($template, $hostAppPath);

            foreach ($template->volume_paths as $volumeName => $mountPath) {
                // Bind host app dir for Laravel (/app) and WordPress (/var/www/html) so the
                // customer file manager and convert import share the same filesystem.
                if ($hostAppPath && in_array($volumeName, self::HOST_BIND_VOLUME_NAMES, true)) {
                    $compose['services'][$containerName]['volumes'][] = "{$hostAppPath}:{$mountPath}";

                    continue;
                }

                // Docker mounts deeper paths last, so a named volume nested inside the bind
                // mount (wp_content under /var/www/html) hides customer files from the file
                // manager, backups and permission repairs no matter how it was declared.
                if ($this->mountIsNestedUnder((string) $mountPath, $bindMountTargets)) {
                    continue;
                }

                $compose['services'][$containerName]['volumes'][] = "{$volumeName}:{$mountPath}";
                $compose['volumes'][$volumeName] = null;
            }

            if ($hostAppPath && ($template->slug ?? '') === 'wordpress' && empty($compose['services'][$containerName]['volumes'])) {
                $compose['services'][$containerName]['volumes'][] = "{$hostAppPath}:/var/www/html";
            }

            if (empty($compose['volumes'])) {
                unset($compose['volumes']);
            }
        }

        // Legacy fallback: ensure runtime templates still mount host app path
        // even when template volume metadata is missing.
        if ($hostAppPath && empty($compose['services'][$containerName]['volumes'])) {
            $mount = ($template->slug ?? '') === 'wordpress' ? '/var/www/html' : '/app';
            $compose['services'][$containerName]['volumes'] = ["{$hostAppPath}:{$mount}"];
        }

        if (($template->slug ?? '') === 'wordpress') {
            $compose['services'][$containerName]['volumes'] ??= [];
            $uploadsMount = $this->wordpressHardening->uploadsIniVolumeMount($containerName);
            if (! in_array($uploadsMount, $compose['services'][$containerName]['volumes'], true)) {
                $compose['services'][$containerName]['volumes'][] = $uploadsMount;
            }
        }

        if (($template->slug ?? '') === 'static-site') {
            $compose['services'][$containerName]['volumes'] ??= [];
            $nginxMount = app(StaticSiteDocrootService::class)->nginxConfigVolumeMount($containerName);
            if (! in_array($nginxMount, $compose['services'][$containerName]['volumes'], true)) {
                $compose['services'][$containerName]['volumes'][] = $nginxMount;
            }
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

        if ($serveNextFrontend && ($template->slug ?? null) === 'laravel') {
            $this->attachLaravelNextSidecarStack(
                $compose,
                $containerName,
                $port,
                $envVars,
                $hostAppPath,
                $laravelDocumentRoot ?: '/app/public',
                $nextFrontendRelativeDir,
                $cpuLimit,
                $memoryLimit,
                $deployment
            );
        }
        $serveNodeWebFrontend = $this->supportsSplitWebWorkloads($template->slug ?? null)
            && ($nodeTopology['topology'] ?? null) === 'split_web_api';
        if ($serveNodeWebFrontend) {
            $this->attachNodeWebSidecarStack(
                $compose,
                $containerName,
                $port,
                $envVars,
                $hostAppPath,
                $nodeTopology,
                (float) $cpuLimit,
                (int) $memoryLimit,
            );
        }

        $this->ensureNamedVolumesDeclared($compose);
        $this->applyMysqlSidecarDatadirRepair($compose);
        $this->elasticResources->apply(
            $compose,
            ($serveNextFrontend || $serveNodeWebFrontend) ? LaravelNextGatewayProxy::BACKEND_SERVICE : $containerName,
            (float) $cpuLimit,
            (int) $memoryLimit
        );

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * Official mysql/mariadb images run `mysqld --initialize` when /var/lib/mysql/mysql
     * is missing. A previous OOM, SSH-killed init, or lost+found leaves other files there,
     * so initialize aborts and `restart: always` crash-loops forever.
     *
     * @param  array<string, mixed>  $compose
     */
    public function applyMysqlSidecarDatadirRepair(array &$compose): void
    {
        foreach ($compose['services'] ?? [] as $name => $service) {
            if (! is_array($service)) {
                continue;
            }

            $image = strtolower((string) ($service['image'] ?? ''));
            if (! str_contains($image, 'mysql') && ! str_contains($image, 'mariadb')) {
                continue;
            }

            $compose['services'][$name]['entrypoint'] = self::mysqlSidecarRepairEntrypoint();
            $this->ensureMysqlSidecarDisablesNativeAio($compose['services'][$name]);
        }
    }

    /**
     * Many MySQL 8 sidecars on one host exhaust fs.aio-max-nr. io_setup() then
     * fails with EAGAIN, initialize aborts, and the datadir is left unusable.
     *
     * @param  array<string, mixed>  $service
     */
    public function ensureMysqlSidecarDisablesNativeAio(array &$service): void
    {
        $command = $service['command'] ?? [];
        if (is_string($command)) {
            $command = preg_split('/\s+/', trim($command)) ?: [];
        }
        if (! is_array($command)) {
            $command = [];
        }

        foreach ($command as $part) {
            if (is_string($part) && str_contains($part, 'innodb-use-native-aio')) {
                return;
            }
        }

        $command[] = '--innodb-use-native-aio=0';
        $service['command'] = array_values($command);
    }

    /**
     * @return list<string>
     */
    public static function mysqlSidecarRepairEntrypoint(): array
    {
        return [
            'bash',
            '-c',
            'if [ -d /var/lib/mysql ] && [ ! -d /var/lib/mysql/mysql ]; then find /var/lib/mysql -mindepth 1 -exec rm -rf {} + || true; fi; exec docker-entrypoint.sh "$@"',
            'talksasa-mysql',
        ];
    }

    /**
     * Promote a Node monorepo into API + web + edge services.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     * @param  array<string, mixed>  $topology
     */
    private function attachNodeWebSidecarStack(
        array &$compose,
        string $containerName,
        int $publicPort,
        array $envVars,
        ?string $hostAppPath,
        array $topology,
        float $cpuLimit,
        int $memoryLimitMb,
    ): void {
        if (! isset($compose['services'][$containerName]) || ! is_array($compose['services'][$containerName]) || ! $hostAppPath) {
            throw new \DomainException('The split Node stack requires a bind-mounted application directory.');
        }
        if ($memoryLimitMb < 512 || $cpuLimit < 0.5) {
            throw new \DomainException(
                'A split Node backend/frontend stack requires at least 0.5 CPU and 512 MB RAM. Upgrade the service plan before redeploying.'
            );
        }

        $backend = $compose['services'][$containerName];
        unset($compose['services'][$containerName], $backend['ports']);
        $backendCpu = max(0.1, round($cpuLimit * 0.55, 2));
        $frontendCpu = max(0.1, round($cpuLimit * 0.40, 2));
        $edgeCpu = max(0.05, round($cpuLimit * 0.05, 2));
        $edgeMemory = max(32, (int) floor($memoryLimitMb * 0.05));
        $frontendMemory = max(128, (int) floor($memoryLimitMb * 0.40));
        $backendMemory = max(96, $memoryLimitMb - $frontendMemory - $edgeMemory);
        $backendPort = (int) data_get($topology, 'backend.port', ContainerNodeWorkloadTopologyService::BACKEND_PORT);
        $frontendPort = (int) data_get($topology, 'frontend.port', ContainerNodeWorkloadTopologyService::FRONTEND_PORT);
        $frontendType = (string) ($topology['frontend_type'] ?? 'nextjs');

        $backend['container_name'] = $containerName;
        $backend['working_dir'] = (string) data_get($topology, 'backend.working_directory');
        $backend['command'] = data_get($topology, 'backend.start_command');
        $backend['expose'] = [(string) $backendPort];
        $backend['environment'] = array_merge($envVars, [
            'PORT' => (string) $backendPort,
            'INTERNAL_API_URL' => 'http://backend:'.$backendPort,
            'BACKEND_URL' => 'http://backend:'.$backendPort,
        ]);
        $this->setComposeResourceLimits($backend, $backendCpu, $backendMemory);

        if ($frontendType === 'vite-spa') {
            $frontend = [
                'image' => 'nginx:1.27-alpine',
                'container_name' => NodeWebGatewayProxy::frontendContainerName($containerName),
                'restart' => $backend['restart'] ?? 'always',
                'expose' => [(string) $frontendPort],
                'volumes' => [
                    $hostAppPath.'/'.data_get($topology, 'frontend.root').'/dist:/usr/share/nginx/html:ro',
                    NodeWebGatewayProxy::viteConfigPath($hostAppPath).':/etc/nginx/conf.d/default.conf:ro',
                ],
                'depends_on' => [NodeWebGatewayProxy::BACKEND_SERVICE],
            ];
        } else {
            $frontendEnv = array_merge($envVars, [
                'PORT' => (string) $frontendPort,
                'HOSTNAME' => '0.0.0.0',
                'INTERNAL_API_URL' => 'http://backend:'.$backendPort,
                'BACKEND_URL' => 'http://backend:'.$backendPort,
                'NEXT_PUBLIC_API_URL' => $envVars['NEXT_PUBLIC_API_URL'] ?? '/api',
            ]);
            $frontend = [
                'image' => $backend['image'],
                'container_name' => NodeWebGatewayProxy::frontendContainerName($containerName),
                'restart' => $backend['restart'] ?? 'always',
                'working_dir' => (string) data_get($topology, 'frontend.working_directory'),
                'command' => data_get($topology, 'frontend.start_command'),
                'environment' => $frontendEnv,
                'expose' => [(string) $frontendPort],
                'volumes' => $backend['volumes'] ?? ["{$hostAppPath}:/app"],
                'depends_on' => [NodeWebGatewayProxy::BACKEND_SERVICE],
            ];
        }
        $this->setComposeResourceLimits($frontend, $frontendCpu, $frontendMemory);

        $edge = [
            'image' => 'node:22-alpine',
            'container_name' => NodeWebGatewayProxy::edgeContainerName($containerName),
            'restart' => $backend['restart'] ?? 'always',
            'environment' => [
                'GATEWAY_PORT' => (string) NodeWebGatewayProxy::EDGE_PORT,
                'BACKEND_HOST' => NodeWebGatewayProxy::BACKEND_SERVICE,
                'BACKEND_PORT' => (string) $backendPort,
                'FRONTEND_HOST' => NodeWebGatewayProxy::FRONTEND_SERVICE,
                'FRONTEND_PORT' => (string) $frontendPort,
            ],
            'ports' => ["{$publicPort}:".NodeWebGatewayProxy::EDGE_PORT],
            'volumes' => [
                NodeWebGatewayProxy::scriptPath($hostAppPath).':'.NodeWebGatewayProxy::containerScriptPath().':ro',
            ],
            'command' => ['node', NodeWebGatewayProxy::containerScriptPath()],
            'depends_on' => [NodeWebGatewayProxy::BACKEND_SERVICE],
        ];
        $this->setComposeResourceLimits($edge, $edgeCpu, $edgeMemory);

        $compose['services'][NodeWebGatewayProxy::BACKEND_SERVICE] = $backend;
        $compose['services'][NodeWebGatewayProxy::FRONTEND_SERVICE] = $frontend;
        $compose['services'][NodeWebGatewayProxy::EDGE_SERVICE] = $edge;
    }

    private function supportsSplitWebWorkloads(?string $slug): bool
    {
        return in_array($slug, ['nodejs', 'python', 'ruby', 'go'], true);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function setComposeResourceLimits(array &$service, float $cpu, int $memoryMb): void
    {
        $service['cpus'] = $cpu;
        $service['mem_limit'] = $memoryMb.'M';
        $service['deploy']['resources']['limits'] = [
            'cpus' => (string) $cpu,
            'memory' => $memoryMb.'M',
        ];
        $service['deploy']['resources']['reservations'] = [
            'cpus' => (string) round($cpu * 0.5, 2),
            'memory' => max(16, (int) floor($memoryMb * 0.5)).'M',
        ];
    }

    /**
     * Promote the Laravel app service into backend + frontend + edge sidecars.
     * Docker DNS: backend, frontend, edge. Public port binds only to edge.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, string>  $envVars
     */
    private function attachLaravelNextSidecarStack(
        array &$compose,
        string $containerName,
        int $publicPort,
        array $envVars,
        ?string $hostAppPath,
        string $documentRoot,
        string $nextFrontendRelativeDir,
        float $cpuLimit,
        int $memoryLimitMb,
        ?ContainerDeployment $deployment = null,
    ): void {
        if (! isset($compose['services'][$containerName]) || ! is_array($compose['services'][$containerName])) {
            return;
        }

        $frontendDir = '/app/'.trim($nextFrontendRelativeDir, '/');
        $backendPort = LaravelNextGatewayProxy::BACKEND_PORT;
        $frontendPort = LaravelNextGatewayProxy::FRONTEND_PORT;
        $edgePort = LaravelNextGatewayProxy::EDGE_INTERNAL_PORT;

        $backendCpu = max(0.1, round($cpuLimit * 0.55, 2));
        $frontendCpu = max(0.1, round($cpuLimit * 0.40, 2));
        $edgeCpu = max(0.05, round($cpuLimit * 0.05, 2));
        $backendMem = max(128, (int) floor($memoryLimitMb * 0.55));
        $frontendMem = max(256, (int) floor($memoryLimitMb * 0.40));
        $edgeMem = max(64, (int) floor($memoryLimitMb * 0.05));

        $backend = $compose['services'][$containerName];
        unset($compose['services'][$containerName]);

        $backend['container_name'] = $containerName;
        unset($backend['ports']);
        $backend['expose'] = [(string) $backendPort];
        $backend['command'] = LaravelNextGatewayProxy::backendComposeCommand($documentRoot, $backendPort);
        $backend['mem_limit'] = $backendMem.'M';
        $backend['cpus'] = $backendCpu;
        $backend['deploy']['resources']['limits'] = [
            'cpus' => (string) $backendCpu,
            'memory' => $backendMem.'M',
        ];
        $backend['deploy']['resources']['reservations'] = [
            'cpus' => (string) round($backendCpu * 0.5, 2),
            'memory' => ((int) floor($backendMem * 0.5)).'M',
        ];

        $publicUrl = $this->resolvePublicAppUrl($deployment, $envVars);
        $apiPublic = $publicUrl !== null ? rtrim($publicUrl, '/').'/api/v1' : null;

        $backendEnv = is_array($backend['environment'] ?? null) ? $backend['environment'] : $envVars;
        $backendEnv['INTERNAL_API_URL'] = 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.$backendPort;
        $backendEnv['BACKEND_URL'] = $backendEnv['INTERNAL_API_URL'];
        $backendEnv['LARAVEL_API_PORT'] = (string) $backendPort;
        if ($publicUrl !== null) {
            $backendEnv['APP_URL'] = $publicUrl;
            $backendEnv['FRONTEND_URL'] = $publicUrl;
            $backendEnv['TALKSASA_CLOUD_URL'] = $backendEnv['TALKSASA_CLOUD_URL'] ?? $publicUrl;
        }
        if ($apiPublic !== null) {
            $backendEnv['API_URL'] = $apiPublic;
        }
        $backend['environment'] = $backendEnv;

        $volumes = $backend['volumes'] ?? [];
        if ($hostAppPath && $volumes === []) {
            $volumes = ["{$hostAppPath}:/app"];
        }

        $frontendEnv = [
            'HOME' => '/tmp',
            'NPM_CONFIG_CACHE' => '/tmp/.npm',
            'npm_config_cache' => '/tmp/.npm',
            'NODE_ENV' => 'production',
            'HOSTNAME' => '0.0.0.0',
            'PORT' => (string) $frontendPort,
            'INTERNAL_API_URL' => 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.$backendPort,
            'BACKEND_URL' => 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.$backendPort,
        ];
        if ($publicUrl !== null) {
            $frontendEnv['NEXT_PUBLIC_APP_URL'] = $publicUrl;
            $frontendEnv['FRONTEND_URL'] = $publicUrl;
            $frontendEnv['APP_URL'] = $publicUrl;
        }
        if ($apiPublic !== null) {
            $frontendEnv['NEXT_PUBLIC_API_URL'] = $apiPublic;
            $frontendEnv['API_URL'] = $apiPublic;
        }
        foreach (['NEXT_PUBLIC_APP_URL', 'NEXT_PUBLIC_API_URL', 'API_URL', 'FRONTEND_URL', 'APP_URL'] as $key) {
            if (! empty($envVars[$key])) {
                $frontendEnv[$key] = (string) $envVars[$key];
            }
        }

        $frontend = [
            'image' => $this->nextSidecarImage('node_image', 'node:20-bookworm-slim'),
            'container_name' => LaravelNextGatewayProxy::frontendContainerName($containerName),
            'restart' => $backend['restart'] ?? 'always',
            'working_dir' => $frontendDir,
            'environment' => $frontendEnv,
            'expose' => [(string) $frontendPort],
            'volumes' => $volumes,
            'command' => LaravelNextGatewayProxy::frontendComposeCommand($frontendDir, $frontendPort),
            'depends_on' => [LaravelNextGatewayProxy::BACKEND_SERVICE],
            'mem_limit' => $frontendMem.'M',
            'cpus' => $frontendCpu,
            'deploy' => [
                'resources' => [
                    'limits' => [
                        'cpus' => (string) $frontendCpu,
                        'memory' => $frontendMem.'M',
                    ],
                    'reservations' => [
                        'cpus' => (string) round($frontendCpu * 0.5, 2),
                        'memory' => ((int) floor($frontendMem * 0.5)).'M',
                    ],
                ],
            ],
        ];

        $edge = [
            'image' => $this->nextSidecarImage('edge_image', 'node:20-alpine'),
            'container_name' => LaravelNextGatewayProxy::edgeContainerName($containerName),
            'restart' => $backend['restart'] ?? 'always',
            'environment' => [
                'GATEWAY_PUBLIC_PORT' => (string) $edgePort,
                'BACKEND_HOST' => LaravelNextGatewayProxy::BACKEND_SERVICE,
                'BACKEND_PORT' => (string) $backendPort,
                'FRONTEND_HOST' => LaravelNextGatewayProxy::FRONTEND_SERVICE,
                'FRONTEND_PORT' => (string) $frontendPort,
            ],
            'ports' => ["{$publicPort}:{$edgePort}"],
            'volumes' => $hostAppPath
                ? [LaravelNextGatewayProxy::hostScriptPath($hostAppPath).':'.LaravelNextGatewayProxy::scriptPathInContainer().':ro']
                : [],
            'command' => ['node', LaravelNextGatewayProxy::scriptPathInContainer()],
            // Edge can serve /api even if Next is still building or restarting.
            'depends_on' => [
                LaravelNextGatewayProxy::BACKEND_SERVICE,
            ],
            'mem_limit' => $edgeMem.'M',
            'cpus' => $edgeCpu,
            'deploy' => [
                'resources' => [
                    'limits' => [
                        'cpus' => (string) $edgeCpu,
                        'memory' => $edgeMem.'M',
                    ],
                ],
            ],
        ];

        if (isset($backend['depends_on'])) {
            // keep db dependency on backend
        }

        $compose['services'][LaravelNextGatewayProxy::BACKEND_SERVICE] = $backend;
        $compose['services'][LaravelNextGatewayProxy::FRONTEND_SERVICE] = $frontend;
        $compose['services'][LaravelNextGatewayProxy::EDGE_SERVICE] = $edge;
    }

    /**
     * @param  array<string, string>  $envVars
     */
    /**
     * Template volume names that are replaced by a bind mount of the host app directory.
     */
    private const HOST_BIND_VOLUME_NAMES = ['app_data', 'wp_data', 'web_root'];

    /**
     * @return list<string>
     */
    private function hostBindMountTargets($template, ?string $hostAppPath): array
    {
        if (! $hostAppPath || ! $template->volume_paths) {
            return [];
        }

        $targets = [];
        foreach ($template->volume_paths as $volumeName => $mountPath) {
            if (in_array($volumeName, self::HOST_BIND_VOLUME_NAMES, true)) {
                $targets[] = rtrim((string) $mountPath, '/');
            }
        }

        return array_values(array_filter($targets, fn (string $path) => $path !== ''));
    }

    /**
     * @param  list<string>  $parents
     */
    private function mountIsNestedUnder(string $mountPath, array $parents): bool
    {
        $mountPath = rtrim($mountPath, '/');

        foreach ($parents as $parent) {
            if ($mountPath !== $parent && str_starts_with($mountPath, $parent.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vite reads its host allowlist once at boot, so a newly bound domain needs the
     * compose env rewritten and the container recreated before it stops being blocked.
     */
    public function syncViteAllowedHosts(Service $service, ContainerDeployment $deployment, ?SSHService $ssh = null): bool
    {
        if (! str_contains((string) $deployment->docker_compose_content, 'vite preview')) {
            return false;
        }

        $deployment->load('domains');
        $expected = $this->viteAllowedHosts(
            $deployment,
            is_array($deployment->env_values) ? $deployment->env_values : []
        );

        if ($expected === '' || $expected === $this->composeViteAllowedHosts($deployment)) {
            return false;
        }

        $ownsConnection = $ssh === null;
        $ssh ??= SSHService::forNode($deployment->node);

        try {
            $this->refreshApplicationRuntimeCompose($service, $deployment, $ssh);

            return true;
        } finally {
            if ($ownsConnection) {
                $ssh->disconnect();
            }
        }
    }

    private function composeViteAllowedHosts(ContainerDeployment $deployment): string
    {
        $matched = preg_match(
            '/'.preg_quote(self::VITE_ALLOWED_HOSTS_ENV, '/').':\s*(.+)/',
            (string) $deployment->docker_compose_content,
            $matches
        );

        return $matched === 1 ? trim($matches[1], " \t\r\n'\"") : '';
    }

    /**
     * Vite 4.5.6+/5.4.12+/6.0.9+ answer with "Blocked request. This host is not allowed"
     * for any Host header that is not localhost or an IP, which is exactly what our
     * nginx proxy forwards. Vite reads this env var on top of the config allowlist.
     *
     * @param  array<string, string>  $envVars
     */
    public function viteAllowedHosts(?ContainerDeployment $deployment, array $envVars = []): string
    {
        $hosts = [];

        foreach (['APP_URL', 'FRONTEND_URL', 'NEXT_PUBLIC_APP_URL', 'VITE_APP_URL'] as $key) {
            $host = parse_url(trim((string) ($envVars[$key] ?? '')), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        if ($deployment) {
            $deployment->loadMissing('node', 'domains');

            foreach ($deployment->domains as $domain) {
                $name = trim((string) $domain->domain);
                if ($name !== '') {
                    $hosts[] = $name;
                }
            }

            $nodeHost = trim((string) ($deployment->node->hostname ?? ''));
            if ($nodeHost !== '') {
                $hosts[] = $nodeHost;
            }
        }

        $allowed = [];
        foreach ($hosts as $host) {
            $host = strtolower(ltrim($host, '.'));
            if (! preg_match('/^[a-z0-9.-]+$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }

            $allowed[$host] = true;
            // Leading dot allows the domain plus every subdomain under it.
            $allowed['.'.$host] = true;
        }

        return implode(',', array_keys($allowed));
    }

    private function resolvePublicAppUrl(?ContainerDeployment $deployment, array $envVars): ?string
    {
        foreach (['FRONTEND_URL', 'APP_URL', 'NEXT_PUBLIC_APP_URL'] as $key) {
            $value = trim((string) ($envVars[$key] ?? ''));
            if ($value !== '' && preg_match('#^https?://#i', $value)) {
                return rtrim($value, '/');
            }
        }

        if ($deployment) {
            $deployment->loadMissing('node', 'domains');
            $active = $deployment->domains->firstWhere('status', 'active');
            if ($active && ! empty($active->domain)) {
                return 'https://'.ltrim((string) $active->domain, '/');
            }

            $url = $deployment->getAccessUrl();
            if (is_string($url) && $url !== '') {
                return rtrim($url, '/');
            }
        }

        return null;
    }

    public function usesLaravelNextSidecarStack(ContainerDeployment $deployment): bool
    {
        $yaml = (string) ($deployment->docker_compose_content ?? '');

        return str_contains($yaml, "\n  frontend:\n")
            && str_contains($yaml, "\n  edge:\n")
            && str_contains($yaml, "\n  backend:\n");
    }

    public function usesNodeWebSidecarStack(ContainerDeployment $deployment): bool
    {
        $meta = is_array($deployment->service?->service_meta) ? $deployment->service->service_meta : [];

        return data_get($meta, 'node_workloads.topology') === 'split_web_api'
            && str_contains((string) $deployment->docker_compose_content, "\n  frontend:\n")
            && str_contains((string) $deployment->docker_compose_content, "\n  edge:\n")
            && str_contains((string) $deployment->docker_compose_content, "\n  backend:\n");
    }

    private function nextSidecarImage(string $key, string $default): string
    {
        try {
            $value = config('containers.next_sidecar.'.$key, $default);

            return is_string($value) && $value !== '' ? $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Declare top-level named volumes referenced by services (e.g. template mysql_data).
     *
     * @param  array<string, mixed>  $compose
     */
    private function ensureNamedVolumesDeclared(array &$compose): void
    {
        foreach ($compose['services'] ?? [] as $serviceConfig) {
            if (! is_array($serviceConfig)) {
                continue;
            }

            foreach ($serviceConfig['volumes'] ?? [] as $mount) {
                if (! is_string($mount) || $mount === '') {
                    continue;
                }

                // Bind mounts: /host/path:..., ./rel:..., ~/...
                if (str_starts_with($mount, '/') || str_starts_with($mount, '.') || str_starts_with($mount, '~')) {
                    continue;
                }

                // Named volume short syntax: name:container_path[:opts]
                if (! preg_match('/^([a-zA-Z0-9][a-zA-Z0-9_.-]*)(:.+)$/', $mount, $matches)) {
                    continue;
                }

                $volumeName = $matches[1];
                $compose['volumes'] ??= [];
                if (! array_key_exists($volumeName, $compose['volumes'])) {
                    $compose['volumes'][$volumeName] = null;
                }
            }
        }
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

    public function waitForNodeApplicationReadiness(
        SSHService $ssh,
        ContainerDeployment $deployment,
        int $timeoutSeconds = 120,
    ): void {
        $port = (int) $deployment->assigned_port;
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / self::HEALTH_CHECK_DELAY));
        $probe = 'code=$(curl -sS -o /dev/null -w "%{http_code}" --connect-timeout 2 --max-time 4 '
            .escapeshellarg('http://127.0.0.1:'.$port.'/').' 2>/dev/null || true); '
            .'case "$code" in [1-5][0-9][0-9]) exit 0;; esac; '
            .'timeout 3 sh -c '.escapeshellarg('</dev/tcp/127.0.0.1/'.$port);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $deployment->touch();
            try {
                $this->waitForContainerRunning($ssh, $deployment->container_name, self::HEALTH_CHECK_DELAY * 2);
                $ssh->exec('sh -lc '.escapeshellarg($probe), 10);

                return;
            } catch (\Throwable) {
                if ($attempt < $maxAttempts - 1) {
                    sleep(self::HEALTH_CHECK_DELAY);
                }
            }
        }

        $diagnostic = trim($ssh->exec(
            'docker inspect --format '
                .escapeshellarg('status={{.State.Status}} exit={{.State.ExitCode}} error={{.State.Error}} command={{json .Config.Cmd}}')
                .' '.escapeshellarg($deployment->container_name).' 2>&1 || true; '
                .'docker logs --tail 80 '.escapeshellarg($deployment->container_name)
                ." 2>&1 | awk 'BEGIN{IGNORECASE=1} !/npm warn deprecated|npm notice/' | tail -n 20",
            20
        ));

        throw new \RuntimeException(
            'Node application did not answer on 127.0.0.1:'.$port.' after '.$timeoutSeconds
            .' seconds.'.($diagnostic !== '' ? ' '.$diagnostic : '')
        );
    }

    public function waitForNodeSplitStackReadiness(
        SSHService $ssh,
        ContainerDeployment $deployment,
        int $timeoutSeconds = 180,
    ): void {
        $backend = $deployment->container_name;
        $frontend = NodeWebGatewayProxy::frontendContainerName($backend);
        $edge = NodeWebGatewayProxy::edgeContainerName($backend);
        $port = (int) $deployment->assigned_port;
        $deadline = time() + max(30, $timeoutSeconds);
        $lastDiagnostic = '';

        while (time() < $deadline) {
            try {
                foreach ([$backend, $frontend, $edge] as $container) {
                    $this->waitForContainerRunning($ssh, $container, self::HEALTH_CHECK_DELAY * 2);
                }
                $ssh->exec(
                    'front=$(curl -sS -D - -o /dev/null --connect-timeout 2 --max-time 5 '
                    .escapeshellarg('http://127.0.0.1:'.$port.'/')." | tr -d '\\r'); "
                    .'api=$(curl -sS -D - -o /dev/null --connect-timeout 2 --max-time 5 '
                    .escapeshellarg('http://127.0.0.1:'.$port.'/api/health')." | tr -d '\\r'); "
                    .'printf "%s" "$front" | grep -qi "^x-talksasa-upstream: frontend$"; '
                    .'printf "%s" "$api" | grep -qi "^x-talksasa-upstream: backend$"',
                    15,
                );

                return;
            } catch (\Throwable $e) {
                $lastDiagnostic = $e->getMessage();
                sleep(self::HEALTH_CHECK_DELAY);
            }
        }

        $logs = trim($ssh->exec(
            'for c in '.escapeshellarg($backend).' '.escapeshellarg($frontend).' '.escapeshellarg($edge)
            .'; do echo "=== $c ==="; docker inspect --format '
            .escapeshellarg('{{.State.Status}} exit={{.State.ExitCode}} restarts={{.RestartCount}}')
            .' "$c" 2>&1 || true; docker logs --tail 40 "$c" 2>&1 || true; done',
            30,
        ));

        throw new \RuntimeException(
            'Split Node stack did not become ready: '.mb_substr($lastDiagnostic."\n".$logs, 0, 4000)
        );
    }

    /**
     * Wait for container to be healthy
     */
    private function waitForContainerHealth(
        SSHService $ssh,
        string $containerName,
        int $timeoutSeconds,
        ?ContainerDeployment $deployment = null,
    ): void {
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / self::HEALTH_CHECK_DELAY));
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $deployment?->touch();
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
        $template = $this->resolveContainerTemplate($service);

        if (! $template) {
            return null;
        }

        return $this->resolveDatabaseTemplate($service, $template);
    }

    /**
     * @return array{database: string, username: string}
     */
    public function canonicalDatabaseIdentifiers(Service $service): array
    {
        return $this->defaultDatabaseIdentifiers($service);
    }

    /**
     * Fix common DB env drift: username used as database name, and mismatched
     * DB_PASSWORD / POSTGRES_PASSWORD / DATABASE_URL values.
     *
     * @param  array<string, mixed>  $envVars
     * @return array{
     *     env: array<string, string>,
     *     corrected: bool,
     *     previous_database: ?string,
     *     database: string,
     *     username: string,
     *     password_aligned: bool
     * }
     */
    public function normalizeDatabaseEnvironment(Service $service, array $envVars, string $databaseType): array
    {
        $canonical = $this->defaultDatabaseIdentifiers($service);
        $env = [];
        foreach ($envVars as $key => $value) {
            if (is_string($key) && $key !== '') {
                $env[$key] = is_scalar($value) || $value === null ? (string) $value : '';
            }
        }

        $wordpress = $this->envLooksLikeWordpress($env);
        if ($wordpress) {
            $username = (string) (
                $env['WORDPRESS_DB_USER']
                ?? $env['MYSQL_USER']
                ?? $env['DB_USERNAME']
                ?: 'wordpress'
            );
            $database = (string) (
                $env['WORDPRESS_DB_NAME']
                ?? $env['MYSQL_DATABASE']
                ?? $env['DB_DATABASE']
                ?: 'wordpress'
            );
        } else {
            $username = (string) (
                $env['DB_USERNAME']
                ?? $env['POSTGRES_USER']
                ?? $env['MYSQL_USER']
                ?? $canonical['username']
            );
            $database = (string) (
                $env['DB_DATABASE']
                ?? $env['POSTGRES_DB']
                ?? $env['MYSQL_DATABASE']
                ?? ''
            );
        }

        $looksLikeUsernameAsDatabase = ! $wordpress && $database !== '' && (
            $database === $username
            || $database === $canonical['username']
            || (bool) preg_match('/^u\d+_s\d+$/', $database)
        );

        $corrected = false;
        $passwordAligned = false;
        $previous = $database !== '' ? $database : null;

        if (! $wordpress && ($database === '' || $looksLikeUsernameAsDatabase)) {
            $database = $canonical['database'];
            $corrected = ($previous !== $database);
        }

        $env['DB_DATABASE'] = $database;
        $env['DB_USERNAME'] = $username !== '' ? $username : $canonical['username'];

        if (in_array($databaseType, ['postgresql'], true)) {
            $dbPassword = trim((string) ($env['DB_PASSWORD'] ?? ''));
            $sidecarPassword = trim((string) ($env['POSTGRES_PASSWORD'] ?? ''));
            $urlPassword = $this->passwordFromDatabaseUrl($env['DATABASE_URL'] ?? null);
            $password = $this->resolveAlignedDatabasePassword($env, [
                'DB_PASSWORD',
                'POSTGRES_PASSWORD',
            ], $env['DATABASE_URL'] ?? null);

            if ($this->databasePasswordsConflict([$dbPassword, $sidecarPassword, $urlPassword])) {
                $passwordAligned = true;
                $corrected = true;
            }

            $env['DB_PASSWORD'] = $password;
            $env['POSTGRES_PASSWORD'] = $password;
            $env['POSTGRES_DB'] = $database;
            $env['POSTGRES_USER'] = $env['DB_USERNAME'];
            $env['DB_CONNECTION'] = 'pgsql';
            $env['DB_HOST'] = (string) (($env['DB_HOST'] ?? '') !== '' ? $env['DB_HOST'] : 'db');
            $env['DB_PORT'] = (string) (($env['DB_PORT'] ?? '') !== '' ? $env['DB_PORT'] : '5432');
            $env['DATABASE_URL'] = sprintf(
                'postgresql://%s:%s@%s:%s/%s',
                rawurlencode($env['DB_USERNAME']),
                rawurlencode($password),
                $env['DB_HOST'],
                $env['DB_PORT'],
                rawurlencode($database)
            );
        } elseif (in_array($databaseType, ['mysql', 'mariadb'], true)) {
            $dbPassword = trim((string) ($env['DB_PASSWORD'] ?? ''));
            $sidecarPassword = trim((string) ($env['MYSQL_PASSWORD'] ?? ''));
            $wordpressPassword = trim((string) ($env['WORDPRESS_DB_PASSWORD'] ?? ''));
            $urlPassword = $this->passwordFromDatabaseUrl($env['DATABASE_URL'] ?? null);
            $password = $this->resolveAlignedDatabasePassword($env, $wordpress
                ? ['WORDPRESS_DB_PASSWORD', 'MYSQL_PASSWORD', 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD']
                : ['DB_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'], $env['DATABASE_URL'] ?? null);

            $conflict = $wordpress
                ? [$wordpressPassword !== '' ? $wordpressPassword : $dbPassword, $sidecarPassword, $urlPassword]
                : [$dbPassword, $sidecarPassword, $urlPassword];
            if ($this->databasePasswordsConflict($conflict)) {
                $passwordAligned = true;
                $corrected = true;
            }

            $env['DB_PASSWORD'] = $password;
            $env['MYSQL_PASSWORD'] = $password;
            if (($env['MYSQL_ROOT_PASSWORD'] ?? '') === '') {
                $env['MYSQL_ROOT_PASSWORD'] = $password;
            }
            $env['MYSQL_DATABASE'] = $database;
            $env['MYSQL_USER'] = $env['DB_USERNAME'];
            $env['DB_CONNECTION'] = (string) (($env['DB_CONNECTION'] ?? '') !== '' ? $env['DB_CONNECTION'] : 'mysql');
            $defaultHost = $wordpress ? 'mysql' : 'db';
            $rawHost = (string) (($env['WORDPRESS_DB_HOST'] ?? '') !== ''
                ? $env['WORDPRESS_DB_HOST']
                : (($env['DB_HOST'] ?? '') !== '' ? $env['DB_HOST'] : $defaultHost));
            $split = $this->splitDatabaseHostAndPort($rawHost, $env['DB_PORT'] ?? '3306');
            $env['DB_HOST'] = $split['host'];
            $env['DB_PORT'] = $split['port'];
            if ($wordpress) {
                $env['WORDPRESS_DB_HOST'] = $split['host'];
                $env['WORDPRESS_DB_NAME'] = $database;
                $env['WORDPRESS_DB_USER'] = $env['DB_USERNAME'];
                $env['WORDPRESS_DB_PASSWORD'] = $password;
            }
            $env['DATABASE_URL'] = sprintf(
                'mysql://%s:%s@%s:%s/%s',
                rawurlencode($env['DB_USERNAME']),
                rawurlencode($password),
                $env['DB_HOST'],
                $env['DB_PORT'],
                rawurlencode($database)
            );
        }

        return [
            'env' => $env,
            'corrected' => $corrected,
            'previous_database' => $previous,
            'database' => $database,
            'username' => $env['DB_USERNAME'],
            'password_aligned' => $passwordAligned,
        ];
    }

    /**
     * Prefer DB_PASSWORD (panel source of truth), then sidecar password, then DATABASE_URL.
     *
     * @param  array<string, string>  $env
     * @param  list<string>  $passwordKeys
     */
    private function resolveAlignedDatabasePassword(array $env, array $passwordKeys, ?string $databaseUrl): string
    {
        foreach ($passwordKeys as $key) {
            $value = trim((string) ($env[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $this->passwordFromDatabaseUrl($databaseUrl) ?? '';
    }

    private function passwordFromDatabaseUrl(?string $databaseUrl): ?string
    {
        if (! is_string($databaseUrl) || $databaseUrl === '') {
            return null;
        }

        $parts = parse_url($databaseUrl);
        if (! is_array($parts) || ! isset($parts['pass']) || $parts['pass'] === '') {
            return null;
        }

        return rawurldecode((string) $parts['pass']);
    }

    /**
     * @param  list<string|null>  $passwords
     */
    private function databasePasswordsConflict(array $passwords): bool
    {
        $unique = [];
        foreach ($passwords as $password) {
            $password = trim((string) $password);
            if ($password === '') {
                continue;
            }
            $unique[$password] = true;
        }

        return count($unique) > 1;
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
                    $this->ensureDatabaseCredentialsSynced($ssh, $containerPath, $databaseTemplate, $envVars);

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

    private function ensureDatabaseCredentialsSynced(
        SSHService $ssh,
        string $containerPath,
        DatabaseTemplate $databaseTemplate,
        array $envVars
    ): void {
        try {
            match ($databaseTemplate->type) {
                'mysql', 'mariadb' => $this->syncMysqlSidecarCredentials($ssh, $containerPath, $envVars),
                'postgresql' => $this->syncPostgresqlSidecarCredentials($ssh, $containerPath, $envVars),
                'mongodb' => $this->syncMongodbSidecarCredentials($ssh, $containerPath, $envVars),
                default => null,
            };
        } catch (\Throwable $e) {
            \Log::warning('Database credential sync failed (may be first deploy)', [
                'container_path' => $containerPath,
                'type' => $databaseTemplate->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function waitForApplicationDatabaseAccess(
        SSHService $ssh,
        string $containerName,
        DatabaseTemplate $databaseTemplate,
        array $envVars,
        int $timeoutSeconds = 180,
        ?string $containerPath = null
    ): void {
        $delaySeconds = 5;
        $maxAttempts = max(1, (int) ceil($timeoutSeconds / $delaySeconds));
        $lastError = null;
        $credentialResyncAttempted = false;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                if ($this->applicationDatabaseAccessReady($ssh, $containerName, $databaseTemplate, $envVars, $containerPath)) {
                    return;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                \Log::debug('Application database access check failed', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
                    'container_name' => $containerName,
                    'error' => $lastError,
                ]);

                if ($this->isMissingDatabaseDriverError($lastError)) {
                    throw new \RuntimeException(
                        'Application could not connect to the database: PDO driver is missing. '
                        .'Install the PDO driver for this database (pdo_pgsql for PostgreSQL) and retry.',
                        0,
                        $e
                    );
                }

                // Password mismatches are common when a volume was kept across env changes.
                // Re-sync sidecar credentials once, then keep polling.
                if (
                    $containerPath
                    && ! $credentialResyncAttempted
                    && $this->isDatabasePasswordAuthenticationError($lastError)
                ) {
                    $credentialResyncAttempted = true;
                    $this->ensureDatabaseCredentialsSynced($ssh, $containerPath, $databaseTemplate, $envVars);
                }
            }

            if ($attempt < $maxAttempts - 1) {
                sleep($delaySeconds);
            }
        }

        $output = $this->extractCommandOutput((string) $lastError);
        if ($this->isDatabasePasswordAuthenticationError((string) $lastError)) {
            throw new \RuntimeException(
                'Application could not connect to the database: password authentication failed. '
                .'The database volume may still have an older password. '
                .'Redeploy with “Reset database” to recreate the volume, or retry after credentials are repaired. '
                .'Detail: '.$output
            );
        }

        $suffix = $lastError ? ' Last error: '.$output : '';

        throw new \RuntimeException(
            'Application could not connect to the database within '.$timeoutSeconds.' seconds.'.$suffix
        );
    }

    private function extractCommandOutput(string $message): string
    {
        if (preg_match('/\bOutput:\s*(.*)\s*\z/s', $message, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($message);
    }

    private function isMissingDatabaseDriverError(string $message): bool
    {
        // Only inspect command Output. The executed PHP source also contains the
        // string "missing_pdo_pgsql", which would false-positive on any SSH failure.
        $output = strtolower($this->extractCommandOutput($message));

        return $output === 'missing_pdo_pgsql'
            || str_contains($output, 'could not find driver')
            || str_contains($output, 'pdo pgsql driver missing');
    }

    private function isDatabasePasswordAuthenticationError(string $message): bool
    {
        $output = strtolower($this->extractCommandOutput($message));

        return str_contains($output, 'password authentication failed')
            || str_contains($output, 'access denied for user')
            || (str_contains($output, 'authentication failed') && str_contains($output, 'sqlstate'));
    }

    private function tearDownStack(SSHService $ssh, string $containerPath, bool $removeVolumes): void
    {
        $composeFile = escapeshellarg($containerPath.'/docker-compose.yml');
        $pathArg = escapeshellarg($containerPath);
        $project = basename(rtrim($containerPath, '/'));
        $safeProject = preg_replace('/[^a-zA-Z0-9_.-]/', '', $project) ?: '';

        try {
            $exists = trim($ssh->exec("[ -f {$composeFile} ] && echo yes || echo no", 10));
            if ($exists === 'yes') {
                $volumeFlag = $removeVolumes ? '-v ' : '';
                @$ssh->exec(
                    "cd {$pathArg} && docker compose -f docker-compose.yml down {$volumeFlag}--remove-orphans",
                    self::DEPLOY_TIMEOUT
                );
                $this->waitForRemovingDockerContainers($ssh, $containerPath);
            }

            // Compose down is a no-op when the file is missing or the project name
            // changed. Exact container_name leftovers still hold the published port.
            if ($safeProject !== '') {
                @$ssh->exec(
                    'docker rm -f '
                    .escapeshellarg($safeProject).' '
                    .escapeshellarg($safeProject.'-db').' '
                    .escapeshellarg($safeProject.'-mysql').' '
                    .'2>/dev/null || true',
                    60
                );
                $this->waitForRemovingDockerContainers($ssh, $containerPath);
            }

            if ($removeVolumes) {
                $this->forceRemoveMysqlNamedVolumes($ssh, $containerPath);
                $this->waitForRemovingDockerContainers($ssh, $containerPath);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to tear down existing compose stack before deploy', [
                'container_path' => $containerPath,
                'remove_volumes' => $removeVolumes,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * compose down -v can leave a restarting MySQL sidecar's named volume in place.
     * Convert redeploys then remount the half-initialized datadir and crash-loop again.
     */
    private function forceRemoveMysqlNamedVolumes(SSHService $ssh, string $containerPath): void
    {
        $pathArg = escapeshellarg($containerPath);
        $dbName = escapeshellarg(basename($containerPath).'-db');
        $mysqlName = escapeshellarg(basename($containerPath).'-mysql');
        $projectGuess = escapeshellarg(basename($containerPath));

        @$ssh->exec(
            "cd {$pathArg} 2>/dev/null || true"
            .' ; docker compose -f docker-compose.yml rm -f -v db mysql 2>/dev/null || true'
            ." ; docker rm -f {$dbName} {$mysqlName} 2>/dev/null || true"
            .' ; project=$(docker compose -f docker-compose.yml config --format '
            .escapeshellarg('{{.Name}}')
            ." 2>/dev/null || echo {$projectGuess})"
            .' ; docker volume rm -f "${project}_db_data" "${project}_mysql_data" 2>/dev/null || true',
            120
        );
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
            'mongodb' => $this->mongodbSidecarIsReady($ssh, $containerPathArg),
            default => true,
        };
    }

    private function mysqlSidecarIsReady(SSHService $ssh, string $containerPathArg, array $envVars): bool
    {
        $password = escapeshellarg((string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''));

        // mysqladmin ping only checks if the daemon is up — it succeeds even with wrong credentials
        // when connecting via the socket. Use a simple connection attempt as well.
        $command = "cd {$containerPathArg} && docker compose exec -T db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null"
            ." || cd {$containerPathArg} && docker compose exec -T -e MYSQL_PWD={$password} db mysqladmin ping -h localhost --silent";

        $ssh->exec($command, 20);

        return true;
    }

    private function postgresqlSidecarIsReady(SSHService $ssh, string $containerPathArg, array $envVars): bool
    {
        $command = "cd {$containerPathArg} && ".$this->postgresqlSidecarReadinessCommand($envVars);

        $ssh->exec($command, 20);

        return true;
    }

    private function mongodbSidecarIsReady(SSHService $ssh, string $containerPathArg): bool
    {
        $command = "cd {$containerPathArg} && ("
            ."docker compose exec -T db mongosh --quiet --eval 'db.runCommand({ping:1}).ok' 2>/dev/null"
            ." || docker compose exec -T db mongo --quiet --eval 'db.runCommand({ping:1}).ok' 2>/dev/null"
            .')';

        $ssh->exec($command, 20);

        return true;
    }

    private function applicationDatabaseAccessReady(
        SSHService $ssh,
        string $containerName,
        DatabaseTemplate $databaseTemplate,
        array $envVars,
        ?string $containerPath = null
    ): bool {
        $result = $this->probeApplicationDatabaseAccess(
            $ssh,
            $containerName,
            (string) $databaseTemplate->type,
            $envVars,
            $this->inferTemplateSlugFromContainerName($containerName),
            $containerPath
        );

        if (! $result['ok']) {
            throw new \RuntimeException($result['error'] ?? 'Application database access failed');
        }

        return true;
    }

    /**
     * Probe whether the app can open a database connection with the given env.
     *
     * PHP stacks use PDO as www-data. Node/Alpine images have neither — those
     * probes use `node` and, if `pg`/`mysql2` are missing, sidecar client + TCP.
     *
     * @param  array<string, mixed>  $envVars
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    public function probeApplicationDatabaseAccess(
        SSHService $ssh,
        string $containerName,
        string $databaseType,
        array $envVars,
        ?string $templateSlug = null,
        ?string $containerPath = null
    ): array {
        $templateSlug = $templateSlug ?: $this->inferTemplateSlugFromContainerName($containerName);

        return match ($databaseType) {
            'mysql', 'mariadb' => $this->probeMysqlApplicationAccess(
                $ssh,
                $containerName,
                $envVars,
                $templateSlug,
                $containerPath
            ),
            'postgresql' => $this->probePostgresqlApplicationAccess(
                $ssh,
                $containerName,
                $envVars,
                $templateSlug,
                $containerPath
            ),
            default => ['ok' => true, 'error' => null, 'driver_missing' => false],
        };
    }

    public function inferTemplateSlugFromContainerName(string $containerName): ?string
    {
        if (preg_match('/-(laravel|php|wordpress|nodejs|python|ruby|go)$/', $containerName, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function applicationDatabaseProbeUsesPhp(?string $templateSlug): bool
    {
        return in_array(strtolower((string) $templateSlug), ['laravel', 'php', 'wordpress'], true);
    }

    public function phpDatabaseProbeCommand(string $containerName, string $script, ?string $templateSlug = null): string
    {
        $user = ContainerDockerExecUserResolver::execUser($templateSlug ?: 'laravel') ?? 'www-data';

        return 'docker exec -u '.escapeshellarg($user).' '.escapeshellarg($containerName)
            .' php -r '.escapeshellarg($script);
    }

    public function isMissingPhpRuntimeProbeError(?string $error): bool
    {
        $message = strtolower((string) $error);
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'unable to find user www-data')
            || str_contains($message, 'no matching entries in passwd')
            || (str_contains($message, 'exec:') && str_contains($message, '"php"'))
            || str_contains($message, 'php: not found')
            || str_contains($message, 'php: executable file not found');
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function nodeDatabaseClientProbeCommand(string $containerName, string $databaseType, array $envVars): string
    {
        return 'docker exec '.escapeshellarg($containerName)
            .' node -e '.escapeshellarg($this->nodeDatabaseClientProbeScript($databaseType, $envVars));
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function nodeDatabaseTcpProbeCommand(string $containerName, array $envVars, string $databaseType): string
    {
        [$host, $port] = $this->applicationDatabaseEndpoint($envVars, $databaseType);
        $script = 'const n=require("net");const s=n.connect({host:'.json_encode($host).',port:'.$port.'},()=>{process.stdout.write("ok");s.end();process.exit(0)});'
            .'s.setTimeout(5000,()=>{process.stderr.write("tcp timeout");process.exit(1)});'
            .'s.on("error",e=>{process.stderr.write(String(e&&e.message?e.message:e));process.exit(1)});';

        return 'docker exec '.escapeshellarg($containerName).' node -e '.escapeshellarg($script);
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function sidecarApplicationCredentialProbeCommand(
        string $containerPath,
        string $databaseType,
        array $envVars
    ): string {
        $pathArg = escapeshellarg($containerPath);
        $creds = $this->applicationDatabaseCredentials($envVars, $databaseType);
        $userArg = escapeshellarg($creds['username']);
        $dbArg = escapeshellarg($creds['database']);
        $passwordArg = escapeshellarg($creds['password']);

        if ($databaseType === 'postgresql') {
            return "cd {$pathArg} && docker compose exec -T -e PGPASSWORD={$passwordArg} db "
                ."psql -h 127.0.0.1 -U {$userArg} -d {$dbArg} -Atc ".escapeshellarg('SELECT 1');
        }

        $serviceArg = escapeshellarg($this->resolveMysqlComposeServiceName($envVars));

        return "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$passwordArg} {$serviceArg} "
            ."mysql -h 127.0.0.1 -u {$userArg} {$dbArg} -N -e ".escapeshellarg('SELECT 1');
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function sidecarTableCountCommand(string $containerPath, string $databaseType, array $envVars): string
    {
        $pathArg = escapeshellarg($containerPath);
        $creds = $this->applicationDatabaseCredentials($envVars, $databaseType);
        $userArg = escapeshellarg($creds['username']);
        $dbArg = escapeshellarg($creds['database']);
        $passwordArg = escapeshellarg($creds['password']);

        if ($databaseType === 'postgresql') {
            $sql = 'SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN (\'pg_catalog\',\'information_schema\')';

            return "cd {$pathArg} && docker compose exec -T -e PGPASSWORD={$passwordArg} db "
                ."psql -h 127.0.0.1 -U {$userArg} -d {$dbArg} -Atc ".escapeshellarg($sql);
        }

        $serviceArg = escapeshellarg($this->resolveMysqlComposeServiceName($envVars));
        $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\'BASE TABLE\'';

        return "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$passwordArg} {$serviceArg} "
            ."mysql -h 127.0.0.1 -u {$userArg} {$dbArg} -N -e ".escapeshellarg($sql);
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function postgresqlSidecarReadinessCommand(array $envVars): string
    {
        $user = escapeshellarg((string) ($envVars['POSTGRES_USER'] ?? $envVars['DB_USERNAME'] ?? 'appuser'));
        $password = escapeshellarg((string) ($envVars['POSTGRES_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''));
        $database = escapeshellarg($this->postgresqlAdminDatabaseCandidates($envVars)[0] ?? 'postgres');

        return "docker compose exec -T -e PGPASSWORD={$password} db pg_isready -U {$user} -d {$database} -h localhost";
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return list<string>
     */
    public function postgresqlAdminDatabaseCandidates(array $envVars): array
    {
        $candidates = [];
        foreach ([
            'postgres',
            (string) ($envVars['POSTGRES_DB'] ?? ''),
            (string) ($envVars['DB_DATABASE'] ?? ''),
            'template1',
        ] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $candidates[$name] = true;
            }
        }

        return array_keys($candidates);
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return list<string>
     */
    public function postgresqlAdminRoleCandidates(array $envVars, ?string $canonical = null): array
    {
        $roles = [];
        foreach ([
            $envVars['TALKSASA_PLATFORM_DB_USERNAME'] ?? null,
            $canonical,
            $envVars['POSTGRES_USER'] ?? null,
            $envVars['DB_USERNAME'] ?? null,
            'postgres',
        ] as $role) {
            $role = trim((string) $role);
            if ($role !== '') {
                $roles[$role] = true;
            }
        }

        return array_keys($roles);
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    private function probeMysqlApplicationAccess(
        SSHService $ssh,
        string $containerName,
        array $envVars,
        ?string $templateSlug,
        ?string $containerPath
    ): array {
        $creds = $this->applicationDatabaseCredentials($envVars, 'mysql');
        $host = $this->applicationDatabaseHost($envVars, $containerName);
        if ($this->isAmbiguousSharedNetworkDatabaseHost($host) && $containerName !== '') {
            $host = $this->sidecarDnsHost($containerName);
        }

        $useMysqli = $templateSlug === 'wordpress' || $this->envLooksLikeWordpress($envVars);
        $script = $useMysqli
            ? $this->phpWordpressMysqliEvalScript($host, 3306, $creds['database'], $creds['username'], $creds['password'])
            : $this->phpPdoEvalScript(
                'mysql:host='.$host.';port=3306;dbname='.$creds['database'],
                $creds['username'],
                $creds['password'],
                '$pdo->query("SELECT 1"); fwrite(STDOUT, "ok"); exit(0);'
            );

        return $this->runDatabaseProbeScript(
            $ssh,
            $containerName,
            'mysql',
            $envVars,
            $script,
            $templateSlug,
            $containerPath
        );
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    private function probePostgresqlApplicationAccess(
        SSHService $ssh,
        string $containerName,
        array $envVars,
        ?string $templateSlug = null,
        ?string $containerPath = null
    ): array {
        $creds = $this->applicationDatabaseCredentials($envVars, 'postgresql');
        [$host, $port] = $this->applicationDatabaseEndpoint($envVars, 'postgresql');

        $script = $this->phpPdoEvalScript(
            'pgsql:host='.$host.';port='.$port.';dbname='.$creds['database'],
            $creds['username'],
            $creds['password'],
            '$pdo->query("SELECT 1"); fwrite(STDOUT, "ok"); exit(0);',
            'fwrite(STDERR, $e->getMessage()); exit(1);'
        );

        $missingDriverGuard = 'if (!in_array("pgsql", PDO::getAvailableDrivers(), true)) {'
            .' fwrite(STDERR, "missing_pdo_pgsql"); exit(2);'
            .'}';

        return $this->runDatabaseProbeScript(
            $ssh,
            $containerName,
            'postgresql',
            $envVars,
            $missingDriverGuard.$script,
            $templateSlug,
            $containerPath
        );
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    private function runDatabaseProbeScript(
        SSHService $ssh,
        string $containerName,
        string $databaseType,
        array $envVars,
        string $phpScript,
        ?string $templateSlug,
        ?string $containerPath
    ): array {
        if ($this->applicationDatabaseProbeUsesPhp($templateSlug)) {
            return $this->execProbeCommand(
                $ssh,
                $this->phpDatabaseProbeCommand($containerName, $phpScript, $templateSlug)
            );
        }

        if ($templateSlug === null || $templateSlug === '' || $templateSlug === 'unknown') {
            $php = $this->execProbeCommand(
                $ssh,
                $this->phpDatabaseProbeCommand($containerName, $phpScript, 'laravel')
            );
            if ($php['ok'] || ! $this->isMissingPhpRuntimeProbeError($php['error'])) {
                return $php;
            }
        }

        return $this->probeNonPhpDatabaseAccess(
            $ssh,
            $containerName,
            $databaseType,
            $envVars,
            $containerPath
        );
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    private function probeNonPhpDatabaseAccess(
        SSHService $ssh,
        string $containerName,
        string $databaseType,
        array $envVars,
        ?string $containerPath
    ): array {
        $node = $this->execProbeCommand(
            $ssh,
            $this->nodeDatabaseClientProbeCommand($containerName, $databaseType, $envVars)
        );
        if ($node['ok'] || ! $this->isMissingNodeDatabaseClientError($node['error'])) {
            return $node;
        }

        $tcp = $this->execProbeCommand(
            $ssh,
            $this->nodeDatabaseTcpProbeCommand($containerName, $envVars, $databaseType)
        );
        if ($containerPath !== null && $containerPath !== '') {
            $auth = $this->execProbeCommand(
                $ssh,
                $this->sidecarApplicationCredentialProbeCommand($containerPath, $databaseType, $envVars)
            );
            if ($auth['ok'] && $tcp['ok']) {
                return ['ok' => true, 'error' => null, 'driver_missing' => false];
            }
            if (! $auth['ok']) {
                return $auth;
            }
            if (! $tcp['ok']) {
                [$host] = $this->applicationDatabaseEndpoint($envVars, $databaseType);

                return [
                    'ok' => false,
                    'error' => 'App container cannot reach '.$host.': '.($tcp['error'] ?? 'tcp failed'),
                    'driver_missing' => false,
                ];
            }
        }

        return $node;
    }

    /**
     * @return array{ok: bool, error: ?string, driver_missing: bool}
     */
    private function execProbeCommand(SSHService $ssh, string $command): array
    {
        try {
            $ssh->exec($command, 20);

            return ['ok' => true, 'error' => null, 'driver_missing' => false];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $output = $this->extractCommandOutput($message);

            return [
                'ok' => false,
                'error' => $output !== '' ? $output : $message,
                'driver_missing' => $this->isMissingDatabaseDriverError($message),
            ];
        }
    }

    public function isMissingNodeDatabaseClientError(?string $error): bool
    {
        $message = strtolower((string) $error);

        return str_contains($message, 'missing_node_db_module')
            || str_contains($message, 'cannot find module')
            || (str_contains($message, 'exec:') && str_contains($message, '"node"'))
            || str_contains($message, 'node: not found');
    }

    /**
     * @param  array<string, mixed>  $envVars
     */
    public function nodeDatabaseClientProbeScript(string $databaseType, array $envVars): string
    {
        $creds = $this->applicationDatabaseCredentials($envVars, $databaseType);
        [$host, $port] = $this->applicationDatabaseEndpoint($envVars, $databaseType);
        $payload = base64_encode(json_encode([
            'host' => $host,
            'port' => $port,
            'user' => $creds['username'],
            'pass' => $creds['password'],
            'db' => $creds['database'],
            'engine' => $databaseType === 'postgresql' ? 'pg' : 'mysql',
        ], JSON_THROW_ON_ERROR));

        return 'const c=JSON.parse(Buffer.from('.json_encode($payload).',"base64").toString("utf8"));'
            .'function load(names){for (const n of names){try{return require(n);}catch(e){}} throw new Error("missing_node_db_module");}'
            .'if(c.engine==="pg"){const {Client}=load(["pg","/app/node_modules/pg","/app/backend/node_modules/pg"]);'
            .'const client=new Client({host:c.host,port:c.port,user:c.user,password:c.pass,database:c.db,connectionTimeoutMillis:5000});'
            .'client.connect().then(()=>client.query("SELECT 1")).then(()=>{process.stdout.write("ok");return client.end();}).then(()=>process.exit(0))'
            .'.catch(e=>{process.stderr.write(String(e&&e.message?e.message:e));process.exit(1);});}'
            .'else{const mysql=load(["mysql2/promise","mysql2","mysql","/app/node_modules/mysql2/promise","/app/node_modules/mysql2","/app/node_modules/mysql"]);'
            .'const fn=mysql.createConnection||mysql;'
            .'Promise.resolve(fn({host:c.host,port:c.port,user:c.user,password:c.pass,database:c.db,connectTimeout:5000})).then(conn=>{'
            .'const q=conn&&conn.query?conn.query("SELECT 1"):Promise.resolve();'
            .'return Promise.resolve(q).then(()=>{process.stdout.write("ok");if(conn&&conn.end)return conn.end();});'
            .'}).then(()=>process.exit(0)).catch(e=>{process.stderr.write(String(e&&e.message?e.message:e));process.exit(1);});}';
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{database: string, username: string, password: string}
     */
    public function applicationDatabaseCredentials(array $envVars, string $databaseType): array
    {
        if ($databaseType === 'postgresql') {
            return [
                'database' => (string) ($envVars['DB_DATABASE'] ?? $envVars['POSTGRES_DB'] ?? 'appdb'),
                'username' => (string) ($envVars['DB_USERNAME'] ?? $envVars['POSTGRES_USER'] ?? 'appuser'),
                'password' => (string) ($envVars['DB_PASSWORD'] ?? $envVars['POSTGRES_PASSWORD'] ?? ''),
            ];
        }

        if ($this->envLooksLikeWordpress($envVars)) {
            return [
                'database' => (string) ($envVars['WORDPRESS_DB_NAME'] ?? $envVars['MYSQL_DATABASE'] ?? $envVars['DB_DATABASE'] ?? 'wordpress'),
                'username' => (string) ($envVars['WORDPRESS_DB_USER'] ?? $envVars['MYSQL_USER'] ?? $envVars['DB_USERNAME'] ?? 'wordpress'),
                'password' => (string) ($envVars['WORDPRESS_DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''),
            ];
        }

        return [
            'database' => (string) ($envVars['DB_DATABASE'] ?? $envVars['MYSQL_DATABASE'] ?? $envVars['WORDPRESS_DB_NAME'] ?? 'appdb'),
            'username' => (string) ($envVars['DB_USERNAME'] ?? $envVars['MYSQL_USER'] ?? $envVars['WORDPRESS_DB_USER'] ?? 'appuser'),
            'password' => (string) ($envVars['DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? $envVars['WORDPRESS_DB_PASSWORD'] ?? ''),
        ];
    }

    /**
     * Official WordPress compose uses WORDPRESS_DB_* — not Laravel s{id}_db / u{user}_s{id}.
     *
     * @param  array<string, mixed>  $envVars
     */
    public function envLooksLikeWordpress(array $envVars): bool
    {
        return trim((string) ($envVars['WORDPRESS_DB_NAME'] ?? '')) !== ''
            || trim((string) ($envVars['WORDPRESS_DB_USER'] ?? '')) !== ''
            || trim((string) ($envVars['WORDPRESS_DB_HOST'] ?? '')) !== ''
            || trim((string) ($envVars['WORDPRESS_DB_PASSWORD'] ?? '')) !== '';
    }

    /**
     * @return array{host: string, port: string}
     */
    public function splitDatabaseHostAndPort(string $host, string|int|null $defaultPort = '3306'): array
    {
        $host = trim($host);
        $defaultPort = (string) ($defaultPort !== null && (string) $defaultPort !== '' ? $defaultPort : '3306');
        if (preg_match('/^(.+):(\d+)$/', $host, $matched) === 1) {
            return ['host' => $matched[1], 'port' => $matched[2]];
        }

        return ['host' => $host !== '' ? $host : 'db', 'port' => $defaultPort];
    }

    /**
     * @param  array<string, mixed>  $envVars
     * @return array{0: string, 1: int}
     */
    private function applicationDatabaseEndpoint(array $envVars, string $databaseType): array
    {
        $host = $this->applicationDatabaseHost($envVars);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            $host = $this->defaultMysqlSidecarHost($envVars);
        }
        $defaultPort = $databaseType === 'postgresql' ? 5432 : 3306;
        $port = (int) (($envVars['DB_PORT'] ?? '') !== '' ? $envVars['DB_PORT'] : $defaultPort);

        return [$host, $port > 0 ? $port : $defaultPort];
    }

    /**
     * Build a `php -r` PDO snippet. Passwords are base64 JSON so `$` / quotes are
     * not interpolated by PHP's double-quoted strings (Doctor 1045 while HTTP 200).
     */
    public function phpPdoEvalScript(
        string $dsn,
        string $username,
        string $password,
        string $onSuccess,
        string $onFailure = 'fwrite(STDERR, $e->getMessage()); exit(1);'
    ): string {
        $payload = base64_encode(json_encode([
            'dsn' => $dsn,
            'user' => $username,
            'pass' => $password,
        ], JSON_THROW_ON_ERROR));

        return '$c=json_decode(base64_decode('.json_encode($payload).'), true);'
            .'try { $pdo = new PDO($c["dsn"], $c["user"], $c["pass"], [PDO::ATTR_TIMEOUT => 5]); '
            .$onSuccess
            .' } catch (Throwable $e) { '.$onFailure.' }';
    }

    /**
     * Count user tables in the application database (best-effort).
     *
     * @param  array<string, mixed>  $envVars
     */
    public function countApplicationDatabaseTables(
        SSHService $ssh,
        string $containerName,
        string $databaseType,
        array $envVars,
        ?string $templateSlug = null,
        ?string $containerPath = null
    ): ?int {
        $templateSlug = $templateSlug ?: $this->inferTemplateSlugFromContainerName($containerName);
        $creds = $this->applicationDatabaseCredentials($envVars, $databaseType);
        [$host, $port] = $this->applicationDatabaseEndpoint($envVars, $databaseType);

        if ($databaseType === 'postgresql') {
            // Count all non-system schemas (not only public) so custom schemas aren't reported as empty.
            $script = $this->phpPdoEvalScript(
                'pgsql:host='.$host.';port='.$port.';dbname='.$creds['database'],
                $creds['username'],
                $creds['password'],
                '$n=$pdo->query("SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN (\'pg_catalog\',\'information_schema\')")->fetchColumn();'
                .'fwrite(STDOUT,(string)$n); exit(0);'
            );
        } elseif (in_array($databaseType, ['mysql', 'mariadb'], true)) {
            $script = $this->phpPdoEvalScript(
                'mysql:host='.$host.';port=3306;dbname='.$creds['database'],
                $creds['username'],
                $creds['password'],
                '$n=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\'BASE TABLE\'")->fetchColumn();'
                .'fwrite(STDOUT,(string)$n); exit(0);'
            );
        } else {
            return null;
        }

        if ($this->applicationDatabaseProbeUsesPhp($templateSlug) || $templateSlug === null) {
            try {
                $output = trim($ssh->exec(
                    $this->phpDatabaseProbeCommand($containerName, $script, $templateSlug ?: 'laravel'),
                    20
                ));
                if (is_numeric($output)) {
                    return (int) $output;
                }
            } catch (\Throwable $e) {
                if ($this->applicationDatabaseProbeUsesPhp($templateSlug) && ! $this->isMissingPhpRuntimeProbeError($e->getMessage())) {
                    return null;
                }
            }
        }

        if ($containerPath !== null && $containerPath !== '') {
            try {
                $output = trim($ssh->exec(
                    $this->sidecarTableCountCommand($containerPath, $databaseType, $envVars),
                    20
                ));

                return is_numeric($output) ? (int) $output : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Sync MySQL/MariaDB user credentials inside a running db sidecar.
     *
     * MySQL only reads MYSQL_USER/MYSQL_PASSWORD on first init.
     * After that, changing the compose env vars has no effect.
     * This method connects as root and applies the credential change.
     * Falls back to --skip-grant-tables if root password is unknown.
     */
    public function syncMysqlSidecarCredentials(
        SSHService $ssh,
        string $containerPath,
        array $envVars
    ): void {
        $pathArg = escapeshellarg($containerPath);
        $dbService = $this->resolveMysqlComposeServiceName($envVars);
        $dbServiceArg = escapeshellarg($dbService);
        $rootPassword = (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? '');
        $database = (string) (
            $envVars['WORDPRESS_DB_NAME']
            ?? $envVars['DB_DATABASE']
            ?? $envVars['MYSQL_DATABASE']
            ?? 'appdb'
        );
        $username = (string) (
            $envVars['WORDPRESS_DB_USER']
            ?? $envVars['DB_USERNAME']
            ?? $envVars['MYSQL_USER']
            ?? 'appuser'
        );
        $password = (string) (
            $envVars['WORDPRESS_DB_PASSWORD']
            ?? $envVars['DB_PASSWORD']
            ?? $envVars['MYSQL_PASSWORD']
            ?? ''
        );

        $this->mysqlDropShadowHosts($ssh, $containerPath, $dbService, $rootPassword, $username);

        $shadowHosts = array_values(array_unique(array_merge(
            $this->detectComposeAppIps($ssh, $containerPath, basename($containerPath)),
            $this->mysqlListUserHosts($ssh, $containerPath, $dbService, $rootPassword, $username)
        )));
        $sql = $this->mysqlPrivilegeRepairSql($database, $username, $password, $shadowHosts);

        $sqlArg = escapeshellarg($sql);
        $rootPwArg = escapeshellarg($rootPassword);
        $mysql = 'mysql --force -u root -e';

        $applied = false;
        $rootLoginFailed = false;

        try {
            $ssh->exec(
                "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} {$mysql} {$sqlArg}",
                30
            );
            $applied = true;
        } catch (\Throwable $e) {
            if ($this->mysqlSidecarGrantShouldStopDatabase($e->getMessage())) {
                $rootLoginFailed = true;
            } else {
                // mysql --force still exits 1 when one DROP/CREATE statement errors
                // (connected user, warning). The rest of the script ran — do not
                // stop the sidecar.
                $applied = true;
            }
        }

        if (! $applied) {
            try {
                $ssh->exec(
                    "cd {$pathArg} && docker compose exec -T {$dbServiceArg} {$mysql} {$sqlArg}",
                    30
                );
                $applied = true;
                $rootLoginFailed = false;
            } catch (\Throwable $e) {
                if ($this->mysqlSidecarGrantShouldStopDatabase($e->getMessage())) {
                    $rootLoginFailed = true;
                } else {
                    $applied = true;
                    $rootLoginFailed = false;
                }
            }
        }

        if ($applied) {
            $this->mysqlKillUserSessions($ssh, $containerPath, $dbService, $rootPassword, $username);
            $this->mysqlDropShadowHosts($ssh, $containerPath, $dbService, $rootPassword, $username);
            $this->mysqlAlignRemainingShadowPasswords(
                $ssh,
                $containerPath,
                $dbService,
                $rootPassword,
                $database,
                $username,
                $password
            );

            if ($this->mysqlUserLoginWorks($ssh, $containerPath, $dbService, $username, $password, $database)) {
                return;
            }

            Log::warning('MySQL GRANT applied but application user still cannot log in; leaving the sidecar running', [
                'container_path' => $containerPath,
                'username' => $username,
                'database' => $database,
            ]);

            return;
        }

        // Taking the sidecar down causes HTTP 2002 for every live request. Only do
        // that when root cannot log in at all — not when GRANT SQL had a warning
        // or the app user still 1045s (that is a password/plugin issue, not skip-grant).
        if (! $rootLoginFailed) {
            throw new \RuntimeException(
                'Could not apply MySQL GRANTs as root. The database was left running. '.$sql
            );
        }

        $this->resetMysqlSidecarWithSkipGrantTables(
            $ssh,
            $pathArg,
            $dbServiceArg,
            $rootPwArg,
            $rootPassword,
            $sql
        );
    }

    /**
     * Confirm the application role can authenticate over TCP (user@%), not only
     * the unix-socket localhost account. Overlay-IP 1045s are user@% failures.
     */
    public function mysqlUserLoginWorks(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $username,
        string $password,
        string $database
    ): bool {
        $pathArg = escapeshellarg($containerPath);
        $dbServiceArg = escapeshellarg($dbService);
        $userArg = escapeshellarg($username);
        $passArg = escapeshellarg($password);
        $dbArg = escapeshellarg($database);
        $sqlArg = escapeshellarg('SELECT 1');

        $commands = [
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$passArg} {$dbServiceArg} "
                ."mysql --protocol=TCP -h 127.0.0.1 -N -B -u {$userArg} {$dbArg} -e {$sqlArg} 2>/dev/null",
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$passArg} {$dbServiceArg} "
                ."mysql -N -B -u {$userArg} {$dbArg} -e {$sqlArg} 2>/dev/null",
        ];

        foreach ($commands as $command) {
            try {
                $out = trim($ssh->exec($command, 15));
            } catch (\Throwable) {
                $out = '';
            }
            if ($out !== '' && preg_match('/^1\\b/m', $out)) {
                return true;
            }
        }

        return false;
    }

    private function resetMysqlSidecarWithSkipGrantTables(
        SSHService $ssh,
        string $pathArg,
        string $dbServiceArg,
        string $rootPwArg,
        string $rootPassword,
        string $sql
    ): void {
        $skipGrantSql = sprintf(
            'FLUSH PRIVILEGES; '
            ."ALTER USER 'root'@'%%' IDENTIFIED BY '%s'; "
            ."ALTER USER 'root'@'localhost' IDENTIFIED BY '%s'; "
            .'%s',
            $this->mysqlQuoteString($rootPassword),
            $this->mysqlQuoteString($rootPassword),
            $sql
        );
        $skipGrantSqlArg = escapeshellarg($skipGrantSql);

        $ssh->exec($this->mysqlSkipGrantRepairScript(
            $pathArg,
            $dbServiceArg,
            $rootPwArg,
            $skipGrantSqlArg
        ), 120);
    }

    /**
     * One-off mysqld with skip-grant-tables. Capture the compose run ID —
     * `--name db_credential_repair` is ignored when the service already has
     * container_name, and `--rm` deletes the container before exec.
     */
    public function mysqlSkipGrantRepairScript(
        string $pathArg,
        string $dbServiceArg,
        string $rootPwArg,
        string $skipGrantSqlArg
    ): string {
        return implode("\n", [
            "cd {$pathArg}",
            'docker rm -f db_credential_repair 2>/dev/null || true',
            "docker compose stop {$dbServiceArg} 2>/dev/null || true",
            "REPAIR_CID=$(docker compose run --no-deps -d --entrypoint \"\" {$dbServiceArg} sh -c \"exec mysqld --skip-grant-tables --skip-networking=false --user=mysql\")",
            'if [ -z "$REPAIR_CID" ]; then docker compose start '.$dbServiceArg.'; exit 1; fi',
            'for i in $(seq 1 25); do if docker exec "$REPAIR_CID" mysqladmin ping --silent 2>/dev/null; then break; fi; sleep 1; done',
            "REPAIR_RESULT=0; docker exec \"\$REPAIR_CID\" mysql --force -u root -e {$skipGrantSqlArg} || REPAIR_RESULT=1",
            'docker stop "$REPAIR_CID" 2>/dev/null || true',
            'docker rm -f "$REPAIR_CID" 2>/dev/null || true',
            "docker compose start {$dbServiceArg}",
            "for i in \$(seq 1 15); do if docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} mysqladmin ping --silent 2>/dev/null; then break; fi; sleep 1; done",
            'exit $REPAIR_RESULT',
        ]);
    }

    /**
     * Recreate `user`@`%` and `user`@`localhost` with a known password.
     *
     * Do not CREATE host-specific accounts (`10.201.0.26`, `10.%`). Those are more
     * specific than `%`, so MySQL 8 uses them for overlay connections. CREATE USER
     * IF NOT EXISTS then rewrites caching_sha2_password in the binlog without
     * updating the hash (MY-010235) and the app 1045s even when `@'%'` is correct.
     *
     * @param  list<string>  $shadowHosts  extra accounts to drop (overlay IPs, 10.%, …)
     */
    public function mysqlPrivilegeRepairSql(string $database, string $username, string $password, array $shadowHosts = []): string
    {
        return implode('; ', $this->mysqlPrivilegeRepairStatements($database, $username, $password, $shadowHosts)).';';
    }

    /**
     * @param  list<string>  $shadowHosts
     * @return list<string>
     */
    public function mysqlPrivilegeRepairStatements(string $database, string $username, string $password, array $shadowHosts = []): array
    {
        $dbIdent = $this->mysqlQuoteIdentifier($database);
        $user = $this->mysqlQuoteString($username);
        $pass = $this->mysqlQuoteString($password);

        $dropHosts = [];
        foreach (array_merge(['10.%', '172.%', '127.0.0.1'], $shadowHosts) as $host) {
            $host = trim((string) $host);
            if ($host === '' || $host === '%' || strtolower($host) === 'localhost') {
                continue;
            }
            $dropHosts[$host] = true;
        }

        $statements = ["CREATE DATABASE IF NOT EXISTS {$dbIdent}"];
        foreach (array_keys($dropHosts) as $host) {
            $statements[] = "DROP USER IF EXISTS '{$user}'@'".$this->mysqlQuoteString($host)."'";
        }

        foreach (['%', 'localhost'] as $host) {
            $h = $this->mysqlQuoteString($host);
            $statements[] = "DROP USER IF EXISTS '{$user}'@'{$h}'";
            // mysql_native_password matches DirectAdmin-imported Laravel and
            // avoids caching_sha2 RSA handshake failures from overlay IPs.
            $statements[] = "CREATE USER '{$user}'@'{$h}' IDENTIFIED WITH mysql_native_password BY '{$pass}'";
            // ALTER still runs under mysql --force when DROP is skipped because
            // the account is connected — that is what actually sets the hash.
            // Do not follow with ALTER USER … IDENTIFIED BY (no plugin): MySQL 8
            // then switches the account to caching_sha2_password and WordPress
            // mysqli from an overlay IP 1045s even when the password is correct.
            $statements[] = "ALTER USER '{$user}'@'{$h}' IDENTIFIED WITH mysql_native_password BY '{$pass}'";
            $statements[] = "GRANT ALL PRIVILEGES ON {$dbIdent}.* TO '{$user}'@'{$h}'";
        }

        $statements[] = "DROP USER IF EXISTS ''@'%'";
        $statements[] = "DROP USER IF EXISTS ''@'localhost'";
        $statements[] = "DROP USER IF EXISTS ''@'127.0.0.1'";
        $statements[] = 'FLUSH PRIVILEGES';

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function mysqlListUserHosts(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $rootPassword,
        string $username
    ): array {
        $pathArg = escapeshellarg($containerPath);
        $dbServiceArg = escapeshellarg($dbService);
        $rootPwArg = escapeshellarg($rootPassword);
        $listSql = escapeshellarg("SELECT host FROM mysql.user WHERE user='".$this->mysqlQuoteString($username)."'");

        $commands = [
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} "
                ."mysql -N -B -u root -e {$listSql} 2>/dev/null || true",
            "cd {$pathArg} && docker compose exec -T {$dbServiceArg} "
                ."mysql -N -B -u root -e {$listSql} 2>/dev/null || true",
        ];

        $hosts = '';
        foreach ($commands as $command) {
            try {
                $hosts = trim($ssh->exec($command, 15));
            } catch (\Throwable) {
                $hosts = '';
            }
            if ($hosts !== '') {
                break;
            }
        }

        $found = [];
        foreach (preg_split("/\r\n|\n|\r/", $hosts) ?: [] as $host) {
            $host = trim($host);
            if ($host !== '') {
                $found[] = $host;
            }
        }

        return $found;
    }

    /**
     * DROP every mysql.user host for this username except `%` and `localhost`.
     * `user`@`10.%` is more specific than `@%`, so a new overlay IP like
     * 10.201.0.11 still 1045s after `user`@`10.201.0.26` was dropped.
     *
     * Built in PHP — a nested mysql|mysql pipe inside `sh -lc` + escapeshellarg
     * produced SQL that never ran, which is why overlay accounts survived Repair.
     *
     * @param  list<string>  $hosts
     */
    public function mysqlDropShadowHostsSql(string $username, array $hosts): string
    {
        $user = $this->mysqlQuoteString($username);
        $statements = [];
        foreach ($hosts as $host) {
            $host = trim((string) $host);
            if ($host === '' || $host === '%' || strtolower($host) === 'localhost') {
                continue;
            }
            $statements[] = "DROP USER IF EXISTS '{$user}'@'".$this->mysqlQuoteString($host)."'";
        }
        if ($statements === []) {
            return '';
        }
        $statements[] = 'FLUSH PRIVILEGES';

        return implode('; ', $statements).';';
    }

    public function mysqlDropShadowHosts(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $rootPassword,
        string $username
    ): void {
        $hosts = array_values(array_unique(array_merge(
            ['10.%', '172.%', '127.0.0.1'],
            $this->detectComposeAppIps($ssh, $containerPath, basename($containerPath)),
            $this->mysqlListUserHosts($ssh, $containerPath, $dbService, $rootPassword, $username)
        )));
        $sql = $this->mysqlDropShadowHostsSql($username, $hosts);
        if ($sql === '') {
            $this->mysqlKillUserSessions($ssh, $containerPath, $dbService, $rootPassword, $username);

            return;
        }

        $this->mysqlKillUserSessions($ssh, $containerPath, $dbService, $rootPassword, $username);

        $pathArg = escapeshellarg($containerPath);
        $dbServiceArg = escapeshellarg($dbService);
        $rootPwArg = escapeshellarg($rootPassword);
        $sqlArg = escapeshellarg($sql);
        $mysql = 'mysql --force -u root -e';
        $commands = [
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} {$mysql} {$sqlArg}",
            "cd {$pathArg} && docker compose exec -T {$dbServiceArg} {$mysql} {$sqlArg}",
        ];

        foreach ($commands as $command) {
            try {
                $ssh->exec($command, 30);

                return;
            } catch (\Throwable $e) {
                if ($this->mysqlSidecarGrantShouldStopDatabase($e->getMessage())) {
                    continue;
                }

                return;
            }
        }
    }

    public function mysqlKillIdsSql(array $ids): string
    {
        $statements = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $statements[] = 'KILL '.$id;
            }
        }

        return $statements === [] ? '' : implode('; ', $statements).';';
    }

    /**
     * If DROP USER cannot remove `user`@`10.%` (active connection), set that
     * account to the known password so overlay clients stop 1045ing.
     *
     * @param  list<string>  $hosts
     */
    public function mysqlAlignHostPasswordsSql(string $database, string $username, string $password, array $hosts): string
    {
        $dbIdent = $this->mysqlQuoteIdentifier($database);
        $user = $this->mysqlQuoteString($username);
        $pass = $this->mysqlQuoteString($password);
        $statements = [];
        foreach ($hosts as $host) {
            $host = trim((string) $host);
            if ($host === '' || $host === '%' || strtolower($host) === 'localhost') {
                continue;
            }
            $h = $this->mysqlQuoteString($host);
            $statements[] = "ALTER USER '{$user}'@'{$h}' IDENTIFIED WITH mysql_native_password BY '{$pass}'";
            $statements[] = "GRANT ALL PRIVILEGES ON {$dbIdent}.* TO '{$user}'@'{$h}'";
        }
        if ($statements === []) {
            return '';
        }
        $statements[] = 'FLUSH PRIVILEGES';

        return implode('; ', $statements).';';
    }

    public function mysqlKillUserSessions(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $rootPassword,
        string $username
    ): void {
        $pathArg = escapeshellarg($containerPath);
        $dbServiceArg = escapeshellarg($dbService);
        $rootPwArg = escapeshellarg($rootPassword);
        $listSql = escapeshellarg(
            "SELECT id FROM information_schema.processlist WHERE user='".$this->mysqlQuoteString($username)."' AND id <> CONNECTION_ID()"
        );

        $raw = '';
        foreach ([
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} mysql -N -B -u root -e {$listSql} 2>/dev/null || true",
            "cd {$pathArg} && docker compose exec -T {$dbServiceArg} mysql -N -B -u root -e {$listSql} 2>/dev/null || true",
        ] as $command) {
            try {
                $raw = trim($ssh->exec($command, 15));
            } catch (\Throwable) {
                $raw = '';
            }
            if ($raw !== '') {
                break;
            }
        }

        $ids = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if (ctype_digit($line)) {
                $ids[] = (int) $line;
            }
        }

        $sql = $this->mysqlKillIdsSql($ids);
        if ($sql === '') {
            return;
        }

        $this->mysqlExecRootSql($ssh, $containerPath, $dbService, $rootPassword, $sql);
    }

    private function mysqlAlignRemainingShadowPasswords(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $rootPassword,
        string $database,
        string $username,
        string $password
    ): void {
        $remaining = [];
        foreach ($this->mysqlListUserHosts($ssh, $containerPath, $dbService, $rootPassword, $username) as $host) {
            if ($host !== '%' && strtolower($host) !== 'localhost') {
                $remaining[] = $host;
            }
        }
        if ($remaining === []) {
            return;
        }

        $sql = $this->mysqlAlignHostPasswordsSql($database, $username, $password, $remaining);
        if ($sql === '') {
            return;
        }

        $this->mysqlExecRootSql($ssh, $containerPath, $dbService, $rootPassword, $sql);
        $this->mysqlKillUserSessions($ssh, $containerPath, $dbService, $rootPassword, $username);
        $this->mysqlDropShadowHosts($ssh, $containerPath, $dbService, $rootPassword, $username);
    }

    private function mysqlExecRootSql(
        SSHService $ssh,
        string $containerPath,
        string $dbService,
        string $rootPassword,
        string $sql
    ): void {
        $pathArg = escapeshellarg($containerPath);
        $dbServiceArg = escapeshellarg($dbService);
        $rootPwArg = escapeshellarg($rootPassword);
        $sqlArg = escapeshellarg($sql);
        $mysql = 'mysql --force -u root -e';
        $commands = [
            "cd {$pathArg} && docker compose exec -T -e MYSQL_PWD={$rootPwArg} {$dbServiceArg} {$mysql} {$sqlArg}",
            "cd {$pathArg} && docker compose exec -T {$dbServiceArg} {$mysql} {$sqlArg}",
        ];

        foreach ($commands as $command) {
            try {
                $ssh->exec($command, 30);

                return;
            } catch (\Throwable $e) {
                if ($this->mysqlSidecarGrantShouldStopDatabase($e->getMessage())) {
                    continue;
                }

                return;
            }
        }
    }

    public function mysqlSidecarGrantShouldStopDatabase(string $message): bool
    {
        return $this->isMysqlRootLoginFailure($message);
    }

    private function isMysqlRootLoginFailure(string $message): bool
    {
        return (bool) preg_match(
            '/access denied for user [\'"]root[\'"]|can\'t connect to (local )?mysql|unknown mysql server host/i',
            $message
        );
    }

    private function mysqlQuoteString(string $value): string
    {
        return str_replace(['\\', "'", "\0"], ['\\\\', "\\'", ''], $value);
    }

    /**
     * @return list<string>
     */
    private function detectComposeAppIps(SSHService $ssh, string $containerPath, ?string $appService = null): array
    {
        $pathArg = escapeshellarg($containerPath);
        $service = $appService ?: basename($containerPath);

        try {
            $id = trim($ssh->exec(
                "cd {$pathArg} && (docker compose ps -q ".escapeshellarg($service).' 2>/dev/null || true) | head -1',
                15
            ));
            if ($id === '') {
                $id = $service;
            }

            $ips = trim($ssh->exec(
                'docker inspect -f "{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}" '
                .escapeshellarg($id).' 2>/dev/null || true',
                15
            ));
        } catch (\Throwable) {
            return [];
        }

        $found = [];
        foreach (preg_split('/\s+/', $ips) ?: [] as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $found[] = $ip;
            }
        }

        return $found;
    }

    private function mysqlQuoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * Unique Docker DNS name for this stack's database sidecar.
     * Service names `db` / `mysql` / `mariadb` are not unique on talksasa-net.
     */
    public function sidecarDnsHost(string $appContainerName): string
    {
        $appContainerName = trim($appContainerName);
        if ($appContainerName === '') {
            return 'db';
        }

        return preg_match('/-wordpress(?:-|$)/', $appContainerName) === 1
            ? $appContainerName.'-mysql'
            : $appContainerName.'-db';
    }

    public function composeDefinesDatabaseSidecar(?string $yaml): bool
    {
        if (! is_string($yaml) || trim($yaml) === '') {
            return false;
        }

        try {
            $compose = Yaml::parse($yaml);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($compose['services'] ?? null)) {
            return false;
        }

        foreach ($compose['services'] as $name => $service) {
            if (! is_array($service)) {
                continue;
            }

            $key = strtolower((string) $name);
            if (in_array($key, ['db', 'mysql', 'mariadb', 'postgres', 'postgresql'], true)) {
                return true;
            }

            $cname = strtolower((string) ($service['container_name'] ?? ''));
            if (str_ends_with($cname, '-db') || str_ends_with($cname, '-mysql') || str_ends_with($cname, '_db')) {
                return true;
            }

            $image = strtolower((string) ($service['image'] ?? ''));
            if (str_contains($image, 'mysql') || str_contains($image, 'mariadb') || str_contains($image, 'postgres')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $envVars
     */
    public function patchComposeMysqlSidecar(
        string $yaml,
        string $containerName,
        DatabaseTemplate $databaseTemplate,
        array $envVars
    ): string {
        if ($this->composeDefinesDatabaseSidecar($yaml)) {
            return $yaml;
        }

        $compose = Yaml::parse($yaml);
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            return $yaml;
        }

        $key = $this->resolveComposeAppServiceKey($compose, $containerName) ?? $containerName;
        $this->injectDatabaseSidecar($compose, $databaseTemplate, $envVars, $key);

        $dns = $this->sidecarDnsHost($containerName);
        if (is_array($compose['services']['db'] ?? null)) {
            $compose['services']['db']['container_name'] = $dns;
            $compose['services']['db']['networks']['default']['aliases'] = [$dns];
        }

        $this->applyMysqlSidecarDatadirRepair($compose);

        return Yaml::dump($compose, 10, 2);
    }

    /**
     * PHP apps switched off static nginx have no MySQL container. Repair used to
     * rewrite DB_HOST to unique sidecar DNS anyway, which cannot resolve.
     */
    public function ensureMysqlSidecarForDeployment(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        DatabaseTemplate $databaseTemplate
    ): string {
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        try {
            $yaml = trim((string) $ssh->exec('cat '.escapeshellarg($containerPath.'/docker-compose.yml'), 15));
        } catch (\Throwable) {
            $yaml = (string) ($deployment->docker_compose_content ?? '');
        }

        if ($this->composeDefinesDatabaseSidecar($yaml)) {
            return 'MySQL sidecar is already in compose.';
        }

        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $dbEnv = $this->mysqlEnvironmentVariables($envVars, $service, $deployment->container_name);
        $envVars = array_merge($envVars, $dbEnv);
        $envVars = $this->pinApplicationDatabaseHost($envVars, $deployment->container_name, 'mysql');

        $patched = $this->patchComposeMysqlSidecar($yaml, $deployment->container_name, $databaseTemplate, $envVars);
        if ($patched === $yaml) {
            throw new \RuntimeException('Could not add a MySQL sidecar to docker-compose.yml.');
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ((int) ($databaseTemplate->id ?? 0) > 0) {
            $meta['database_id'] = (int) $databaseTemplate->id;
        }
        $meta['env_values'] = array_merge(is_array($meta['env_values'] ?? null) ? $meta['env_values'] : [], $envVars);
        $service->update(['service_meta' => $meta]);
        $deployment->update([
            'docker_compose_content' => $patched,
            'env_values' => $envVars,
        ]);
        $ssh->upload($patched, $containerPath.'/docker-compose.yml');

        $this->ensureSharedDockerNetwork($ssh);
        $ssh->exec(
            'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml up -d --no-deps db',
            180
        );

        $dns = $this->sidecarDnsHost($deployment->container_name);
        $this->attachSidecarNetworkAlias($ssh, $dns, $dns);

        app(DirectAdminToContainerMigrationService::class)->waitForComposeMysql(
            $ssh,
            $containerPath,
            'db',
            (string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? ''),
            180,
            'root',
            false,
        );

        $this->syncMysqlSidecarCredentials($ssh, $containerPath, $envVars);
        app(ContainerEnvironmentService::class)->syncDotEnvFile($ssh, $service, $deployment->fresh(), $envVars);
        $this->restartAppService($ssh, $deployment->fresh());

        return 'Added a MySQL sidecar at '.$dns.' and pointed the app at s'
            .max(1, (int) $service->id).'_db. Files were kept. This volume is new and empty — import the DirectAdmin dump if the site needs existing tables.';
    }

    public function isAmbiguousSharedNetworkDatabaseHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return true;
        }
        if ($this->hostLooksLikeMysqlUnixSocket($host)) {
            return true;
        }
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return in_array($host, ['db', 'mysql', 'mariadb', 'localhost', '127.0.0.1'], true);
    }

    /**
     * DirectAdmin stores `localhost:/var/lib/mysql/mysql.sock`. Docker has no such socket —
     * the sidecar is TCP on this stack's unique DNS name.
     */
    public function hostLooksLikeMysqlUnixSocket(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }

        return str_contains($host, 'mysql.sock')
            || str_contains($host, '/var/lib/mysql')
            || str_starts_with($host, '/');
    }

    /**
     * @return list<string>
     */
    public function mysqlUnixSocketEnvKeys(): array
    {
        return ['DB_SOCKET', 'MYSQL_UNIX_SOCKET', 'MYSQL_SOCKET', 'SOCKET'];
    }

    /**
     * @param  array<string, mixed>  $env
     */
    public function envUsesMysqlUnixSocket(array $env): bool
    {
        foreach ($this->mysqlUnixSocketEnvKeys() as $key) {
            if (trim((string) ($env[$key] ?? '')) !== '') {
                return true;
            }
        }

        foreach (['DB_HOST', 'MYSQL_HOST', 'WORDPRESS_DB_HOST'] as $key) {
            if ($this->hostLooksLikeMysqlUnixSocket((string) ($env[$key] ?? ''))) {
                return true;
            }
        }

        $url = strtolower((string) ($env['DATABASE_URL'] ?? ''));

        return str_contains($url, 'mysql.sock') || str_contains($url, '/var/lib/mysql');
    }

    /**
     * @param  array<string, mixed>  $env
     * @return array<string, mixed>
     */
    public function stripMysqlUnixSocketEnv(array $env): array
    {
        foreach ($this->mysqlUnixSocketEnvKeys() as $key) {
            unset($env[$key]);
        }

        return $env;
    }

    /**
     * Hostname the app container should use for PDO / Laravel.
     *
     * @param  array<string, mixed>  $envVars
     */
    public function applicationDatabaseHost(array $envVars, ?string $appContainerName = null): string
    {
        $wordpressHost = trim((string) ($envVars['WORDPRESS_DB_HOST'] ?? ''));
        $host = $wordpressHost !== ''
            ? $wordpressHost
            : trim((string) ($envVars['DB_HOST'] ?? $envVars['MYSQL_HOST'] ?? ''));
        if (preg_match('/^(.+):(\d+)$/', $host, $matched) === 1) {
            $host = $matched[1];
        }

        if ($appContainerName && $this->isAmbiguousSharedNetworkDatabaseHost($host)) {
            return $this->sidecarDnsHost($appContainerName);
        }

        if ($this->isAmbiguousSharedNetworkDatabaseHost($host)) {
            $fromEnv = trim((string) ($envVars['TALKSASA_DB_DNS'] ?? ''));
            if ($fromEnv !== '') {
                return $fromEnv;
            }
        }

        if ($this->hostLooksLikeMysqlUnixSocket($host)) {
            return $this->defaultMysqlSidecarHost($envVars);
        }

        return $host !== '' ? $host : $this->defaultMysqlSidecarHost($envVars);
    }

    /**
     * WordPress compose names the sidecar `mysql`; Laravel injects `db`.
     *
     * @param  array<string, mixed>  $envVars
     */
    public function defaultMysqlSidecarHost(array $envVars): string
    {
        if (trim((string) ($envVars['WORDPRESS_DB_NAME'] ?? '')) !== ''
            || trim((string) ($envVars['WORDPRESS_DB_USER'] ?? '')) !== ''
            || trim((string) ($envVars['WORDPRESS_DB_HOST'] ?? '')) !== '') {
            return 'mysql';
        }

        return 'db';
    }

    /**
     * Official wordpress images speak mysqli. PDO `could not find driver` is a false critical.
     */
    public function phpWordpressMysqliEvalScript(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): string {
        $payload = base64_encode(json_encode([
            'host' => $host,
            'port' => $port,
            'db' => $database,
            'user' => $username,
            'pass' => $password,
        ], JSON_THROW_ON_ERROR));

        return '$c=json_decode(base64_decode('.json_encode($payload).'), true);'
            .'if (!function_exists("mysqli_connect")) { fwrite(STDERR, "could not find driver"); exit(2); }'
            .'$m=@mysqli_init();'
            .'if (!$m || !@$m->real_connect($c["host"], $c["user"], $c["pass"], $c["db"], (int) $c["port"])) {'
            .' fwrite(STDERR, ($m && $m->connect_error) ? $m->connect_error : "mysqli connect failed"); exit(1);'
            .'}'
            .'fwrite(STDOUT, "ok"); exit(0);';
    }

    /**
     * @return array{
     *     DB_NAME: ?string,
     *     DB_USER: ?string,
     *     DB_PASSWORD: ?string,
     *     DB_HOST: ?string,
     *     password_uses_env: bool
     * }
     */
    public function extractWordPressConfigDatabaseDefines(string $wpConfig): array
    {
        $out = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => null,
            'password_uses_env' => false,
        ];

        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $key) {
            if (preg_match(
                "/define\\s*\\(\\s*['\"]{$key}['\"]\\s*,\\s*getenv_docker\\s*\\(\\s*['\"][^'\"]+['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/i",
                $wpConfig,
                $m
            ) === 1) {
                $out[$key] = $m[1];
                if ($key === 'DB_PASSWORD') {
                    $out['password_uses_env'] = true;
                }

                continue;
            }

            if (preg_match(
                "/define\\s*\\(\\s*['\"]{$key}['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/i",
                $wpConfig,
                $m
            ) === 1) {
                $out[$key] = $m[1];
            }
        }

        return $out;
    }

    /**
     * Official wordpress:* images generate getenv_docker() defines once. Later
     * compose / .env changes never update wp-config.php, so Repair must rewrite
     * the defines to the password GRANT just set.
     *
     * @param  array{DB_NAME?: string, DB_USER?: string, DB_PASSWORD?: string, DB_HOST?: string}  $credentials
     */
    public function rewriteWordPressConfigDatabaseDefines(string $wpConfig, array $credentials): string
    {
        $map = [
            'DB_NAME' => (string) ($credentials['DB_NAME'] ?? ''),
            'DB_USER' => (string) ($credentials['DB_USER'] ?? ''),
            'DB_PASSWORD' => (string) ($credentials['DB_PASSWORD'] ?? ''),
            'DB_HOST' => (string) ($credentials['DB_HOST'] ?? ''),
        ];

        $text = $wpConfig;
        foreach ($map as $key => $value) {
            if ($value === '') {
                continue;
            }
            $repl = 'define(\''.$key.'\', '.$this->phpSingleQuotedString($value).')';
            $pattern = "/define\\s*\\(\\s*['\"]{$key}['\"]\\s*,\\s*(?:getenv_docker\\s*\\([^;]+\\)|['\"].*?['\"])\\s*\\)/i";
            if (preg_match($pattern, $text) === 1) {
                $text = preg_replace($pattern, $repl, $text, 1) ?? $text;

                continue;
            }

            $text = preg_replace('/<\\?php\\b/', "<?php\n".$repl.';', $text, 1) ?? $text;
        }

        return $text;
    }

    /**
     * Credentials WordPress is actually using: container WORDPRESS_DB_* and
     * wp-config.php (literal defines win over getenv_docker fallbacks).
     *
     * @return array<string, string>
     */
    public function readWordPressRuntimeDatabaseCredentials(SSHService $ssh, string $containerName): array
    {
        $fromFile = $this->extractWordPressConfigDatabaseDefines(
            $this->readWordPressConfigFile($ssh, $containerName)
        );
        $fromEnv = [];
        foreach ([
            'WORDPRESS_DB_HOST' => 'DB_HOST',
            'WORDPRESS_DB_NAME' => 'DB_NAME',
            'WORDPRESS_DB_USER' => 'DB_USER',
            'WORDPRESS_DB_PASSWORD' => 'DB_PASSWORD',
        ] as $envKey => $dbKey) {
            try {
                $value = trim($ssh->exec(
                    'docker exec '.escapeshellarg($containerName)
                    .' printenv '.escapeshellarg($envKey).' 2>/dev/null || true',
                    10
                ));
            } catch (\Throwable) {
                $value = '';
            }
            if ($value !== '') {
                $fromEnv[$envKey] = $value;
                $fromEnv[$dbKey] = $value;
            }
        }

        $password = '';
        if ($fromFile['password_uses_env']) {
            $password = (string) ($fromEnv['WORDPRESS_DB_PASSWORD'] ?? $fromFile['DB_PASSWORD'] ?? '');
        } else {
            $password = (string) ($fromFile['DB_PASSWORD'] ?? $fromEnv['WORDPRESS_DB_PASSWORD'] ?? '');
        }

        $merged = [
            'WORDPRESS_DB_HOST' => (string) ($fromEnv['WORDPRESS_DB_HOST'] ?? $fromFile['DB_HOST'] ?? ''),
            'WORDPRESS_DB_NAME' => (string) ($fromEnv['WORDPRESS_DB_NAME'] ?? $fromFile['DB_NAME'] ?? ''),
            'WORDPRESS_DB_USER' => (string) ($fromEnv['WORDPRESS_DB_USER'] ?? $fromFile['DB_USER'] ?? ''),
            'WORDPRESS_DB_PASSWORD' => $password,
        ];
        if ($password !== '') {
            $merged['WORDPRESS_CONFIG_DB_PASSWORD'] = $password;
        }

        return array_filter($merged, static fn ($value) => $value !== '');
    }

    public function rewriteWordPressConfigDatabaseCredentials(
        SSHService $ssh,
        string $containerName,
        array $credentials
    ): void {
        $raw = $this->readWordPressConfigFile($ssh, $containerName);
        if ($raw === '') {
            return;
        }

        $updated = $this->rewriteWordPressConfigDatabaseDefines($raw, $credentials);
        if ($updated === $raw) {
            return;
        }

        $hostPath = self::CONTAINER_BASE_PATH.'/'.$containerName.'/app/wp-config.php';
        try {
            $ssh->upload($updated, $hostPath);

            return;
        } catch (\Throwable $e) {
            Log::warning('Could not write wp-config.php on the host; trying in-container write', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }

        $payload = base64_encode($updated);
        $php = 'file_put_contents("/var/www/html/wp-config.php", base64_decode('.json_encode($payload).'));';
        $ssh->exec(
            'docker exec '.escapeshellarg($containerName).' php -r '.escapeshellarg($php),
            20
        );
    }

    private function readWordPressConfigFile(SSHService $ssh, string $containerName): string
    {
        $hostPath = self::CONTAINER_BASE_PATH.'/'.$containerName.'/app/wp-config.php';
        try {
            $exists = trim($ssh->exec('test -f '.escapeshellarg($hostPath).' && echo yes || echo no', 10));
            if ($exists === 'yes') {
                return (string) $ssh->exec('cat '.escapeshellarg($hostPath), 20);
            }
        } catch (\Throwable) {
        }

        try {
            return (string) $ssh->exec(
                'docker exec '.escapeshellarg($containerName)
                .' cat /var/www/html/wp-config.php 2>/dev/null || true',
                20
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function phpSingleQuotedString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * HTTPS origin for a bound domain (Cloudflare/node TLS). Null for http://ip:port.
     */
    public function httpsOriginForBoundDomain(ContainerDeployment $deployment): ?string
    {
        try {
            $live = (string) ($deployment->getAccessUrl() ?? '');
        } catch (\Throwable) {
            return null;
        }
        $liveScheme = strtolower((string) parse_url($live, PHP_URL_SCHEME));
        $liveHost = strtolower((string) parse_url($live, PHP_URL_HOST));
        if ($liveScheme !== 'https' || $liveHost === '') {
            return null;
        }
        if (str_starts_with($liveHost, 'www.')) {
            $liveHost = substr($liveHost, 4);
        }

        return 'https://'.$liveHost;
    }

    public function urlIsHttpOnHttpsOrigin(string $url, string $httpsOrigin): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $originHost = strtolower((string) parse_url($httpsOrigin, PHP_URL_HOST));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (str_starts_with($originHost, 'www.')) {
            $originHost = substr($originHost, 4);
        }

        return $scheme === 'http' && $host !== '' && $host === $originHost;
    }

    /**
     * DirectAdmin .env often keeps APP_URL=http://domain after the site is served on https://.
     *
     * @param  array<string, mixed>  $env
     */
    public function httpsAppUrlWhenLiveDomainIsSecure(ContainerDeployment $deployment, array $env): ?string
    {
        $httpsOrigin = $this->httpsOriginForBoundDomain($deployment);
        if ($httpsOrigin === null) {
            return null;
        }
        $appUrl = trim((string) ($env['APP_URL'] ?? ''));
        if (! $this->urlIsHttpOnHttpsOrigin($appUrl, $httpsOrigin)) {
            return null;
        }

        return $httpsOrigin;
    }

    /**
     * Point DB_HOST / DATABASE_URL at this stack's sidecar container name.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public function pinApplicationDatabaseHost(array $env, string $appContainerName, string $databaseType): array
    {
        $env = $this->stripMysqlUnixSocketEnv($env);
        $host = $this->applicationDatabaseHost($env, $appContainerName);
        $env['DB_HOST'] = $host;
        $env['TALKSASA_DB_DNS'] = $host;
        $username = (string) ($env['DB_USERNAME'] ?? $env['MYSQL_USER'] ?? $env['POSTGRES_USER'] ?? '');
        $password = (string) ($env['DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? $env['POSTGRES_PASSWORD'] ?? '');
        $database = (string) ($env['DB_DATABASE'] ?? $env['MYSQL_DATABASE'] ?? $env['POSTGRES_DB'] ?? '');
        if (trim((string) ($env['WORDPRESS_DB_NAME'] ?? '')) !== ''
            || trim((string) ($env['WORDPRESS_DB_USER'] ?? '')) !== ''
            || trim((string) ($env['WORDPRESS_DB_HOST'] ?? '')) !== '') {
            $env['WORDPRESS_DB_HOST'] = $host;
            $username = (string) ($env['WORDPRESS_DB_USER'] ?? $username);
            $password = (string) ($env['WORDPRESS_DB_PASSWORD'] ?? $password);
            $database = (string) ($env['WORDPRESS_DB_NAME'] ?? $database);
        }

        if (in_array($databaseType, ['mysql', 'mariadb'], true)) {
            $port = (string) (($env['DB_PORT'] ?? '') !== '' ? $env['DB_PORT'] : '3306');
            $env['DB_PORT'] = $port;
            $env['MYSQL_HOST'] = $host;
            $env['DATABASE_URL'] = sprintf(
                'mysql://%s:%s@%s:%s/%s',
                rawurlencode($username),
                rawurlencode($password),
                $host,
                $port,
                rawurlencode($database)
            );
        } elseif ($databaseType === 'postgresql') {
            $port = (string) (($env['DB_PORT'] ?? '') !== '' ? $env['DB_PORT'] : '5432');
            $env['DB_PORT'] = $port;
            $env['DATABASE_URL'] = sprintf(
                'postgresql://%s:%s@%s:%s/%s',
                rawurlencode($username),
                rawurlencode($password),
                $host,
                $port,
                rawurlencode($database)
            );
        }

        return $env;
    }

    /**
     * Compose service name for `docker compose exec` (project-scoped).
     * Never use the unique *-db DNS hostname here — that is only for PDO.
     *
     * @param  array<string, mixed>  $envVars
     */
    public function resolveMysqlComposeServiceName(array $envVars): string
    {
        if (! empty($envVars['WORDPRESS_DB_NAME'])
            || ! empty($envVars['WORDPRESS_DB_HOST'])
            || ! empty($envVars['WORDPRESS_DB_USER'])) {
            return 'mysql';
        }

        $host = (string) ($envVars['DB_HOST'] ?? $envVars['WORDPRESS_DB_HOST'] ?? 'db');
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        $host = strtolower(trim($host));
        if (in_array($host, ['mysql', 'mariadb'], true)) {
            return $host;
        }

        return 'db';
    }

    /**
     * Sync PostgreSQL user credentials inside a running db sidecar.
     *
     * The official postgres image only reads POSTGRES_USER/POSTGRES_PASSWORD
     * on first init (when the data directory is empty). Subsequent env changes are ignored.
     * Connect via the container's local socket (peer/trust) so a drifted password can still be reset.
     *
     * The application role in .env (e.g. washflow_user) may not exist yet, so it cannot be used
     * as the admin connection. Try platform/canonical/postgres roles first, then create/alter
     * the application role.
     */
    public function syncPostgresqlSidecarCredentials(
        SSHService $ssh,
        string $containerPath,
        array $envVars,
        ?Service $service = null
    ): void {
        $pathArg = escapeshellarg($containerPath);
        $database = (string) ($envVars['DB_DATABASE'] ?? $envVars['POSTGRES_DB'] ?? 'appdb');
        $username = (string) ($envVars['DB_USERNAME'] ?? $envVars['POSTGRES_USER'] ?? 'appuser');
        $password = (string) ($envVars['DB_PASSWORD'] ?? $envVars['POSTGRES_PASSWORD'] ?? '');

        $roleLiteral = "'".str_replace("'", "''", $username)."'";
        $dbLiteral = "'".str_replace("'", "''", $database)."'";
        $userIdent = '"'.str_replace('"', '""', $username).'"';
        $dbIdent = '"'.str_replace('"', '""', $database).'"';
        $passwordTag = 'ts'.bin2hex(random_bytes(4));
        $passwordLiteral = '$'.$passwordTag.'$'.$password.'$'.$passwordTag.'$';

        $roleSql = 'DO $$ BEGIN '
            .'IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '.$roleLiteral.') THEN '
            .'CREATE ROLE '.$userIdent.' WITH LOGIN PASSWORD '.$passwordLiteral.'; '
            .'ELSE '
            .'ALTER ROLE '.$userIdent.' WITH LOGIN PASSWORD '.$passwordLiteral.'; '
            .'END IF; '
            .'END $$;';

        $createDbSql = 'SELECT 1 FROM pg_database WHERE datname = '.$dbLiteral;
        $createDbIfMissingSql = 'CREATE DATABASE '.$dbIdent.' OWNER '.$userIdent;
        $ownerSql = 'ALTER DATABASE '.$dbIdent.' OWNER TO '.$userIdent;
        $grantSql = 'GRANT ALL PRIVILEGES ON DATABASE '.$dbIdent.' TO '.$userIdent;
        $schemaSql = 'GRANT ALL ON SCHEMA public TO '.$userIdent.'; '
            .'ALTER SCHEMA public OWNER TO '.$userIdent.';';

        $admin = $this->resolvePostgresqlAdminConnection($ssh, $containerPath, $envVars, $service);
        $adminUserArg = escapeshellarg($admin['username']);
        $adminPasswordArg = escapeshellarg($admin['password']);
        $usePassword = $admin['use_password'];
        $asOsUser = $admin['as_os_user'] ?? null;
        $catalogDatabase = (string) ($admin['database'] ?? 'postgres');

        $run = function (string $sql, ?string $databaseName = null) use ($ssh, $pathArg, $adminUserArg, $adminPasswordArg, $usePassword, $asOsUser, $catalogDatabase): void {
            $sqlArg = escapeshellarg($sql);
            $dbArg = escapeshellarg($databaseName ?? $catalogDatabase);

            if (is_string($asOsUser) && $asOsUser !== '') {
                $osUserArg = escapeshellarg($asOsUser);
                $ssh->exec(
                    "cd {$pathArg} && docker compose exec -T -u {$osUserArg} db "
                    ."psql -v ON_ERROR_STOP=1 -U {$adminUserArg} -d {$dbArg} -c {$sqlArg}",
                    30
                );

                return;
            }

            if (! $usePassword) {
                $ssh->exec(
                    "cd {$pathArg} && docker compose exec -T db "
                    ."psql -v ON_ERROR_STOP=1 -U {$adminUserArg} -d {$dbArg} -c {$sqlArg}",
                    30
                );

                return;
            }

            $ssh->exec(
                "cd {$pathArg} && docker compose exec -T -e PGPASSWORD={$adminPasswordArg} db "
                ."psql -v ON_ERROR_STOP=1 -U {$adminUserArg} -d {$dbArg} -c {$sqlArg}",
                30
            );
        };

        $run($roleSql);

        $exists = false;
        try {
            if (is_string($asOsUser) && $asOsUser !== '') {
                $osUserArg = escapeshellarg($asOsUser);
                $checkCmd = "cd {$pathArg} && docker compose exec -T -u {$osUserArg} db "
                    ."psql -U {$adminUserArg} -d ".escapeshellarg($catalogDatabase).' -Atc '.escapeshellarg($createDbSql);
            } elseif (! $usePassword) {
                $checkCmd = "cd {$pathArg} && docker compose exec -T db "
                    ."psql -U {$adminUserArg} -d ".escapeshellarg($catalogDatabase).' -Atc '.escapeshellarg($createDbSql);
            } else {
                $checkCmd = "cd {$pathArg} && docker compose exec -T -e PGPASSWORD={$adminPasswordArg} db "
                    ."psql -U {$adminUserArg} -d ".escapeshellarg($catalogDatabase).' -Atc '.escapeshellarg($createDbSql);
            }
            $output = trim($ssh->exec($checkCmd, 20));
            $exists = $output === '1';
        } catch (\Throwable) {
            $exists = false;
        }

        if (! $exists) {
            $run($createDbIfMissingSql);
        }

        $run($ownerSql);
        $run($grantSql);
        $run($schemaSql, $database);
    }

    /**
     * Find a Postgres superuser that can administer the sidecar (create/alter roles).
     *
     * Non-superuser app roles (e.g. washflow_user) may connect via local trust but cannot
     * ALTER ROLE passwords on modern PostgreSQL.
     *
     * @param  array<string, mixed>  $envVars
     * @return array{username: string, password: string, use_password: bool, as_os_user: ?string, database: string}
     */
    public function resolvePostgresqlAdminConnection(
        SSHService $ssh,
        string $containerPath,
        array $envVars,
        ?Service $service = null
    ): array {
        $pathArg = escapeshellarg($containerPath);
        $canonical = $service ? $this->defaultDatabaseIdentifiers($service)['username'] : null;
        $appUsername = trim((string) ($envVars['DB_USERNAME'] ?? $envVars['POSTGRES_USER'] ?? ''));
        $usernames = array_fill_keys($this->postgresqlAdminRoleCandidates($envVars, $canonical), true);
        $databases = $this->postgresqlAdminDatabaseCandidates($envVars);

        // Move app username to the end so we don't pick a non-superuser trust login first.
        if ($appUsername !== '' && isset($usernames[$appUsername])) {
            unset($usernames[$appUsername]);
            $usernames[$appUsername] = true;
        }

        $passwords = [];
        foreach ([
            '',
            (string) ($envVars['TALKSASA_PLATFORM_DB_PASSWORD'] ?? ''),
            (string) ($envVars['POSTGRES_PASSWORD'] ?? ''),
            (string) ($envVars['DB_PASSWORD'] ?? ''),
        ] as $password) {
            $passwords[] = $password;
        }
        $passwords = array_values(array_unique($passwords, SORT_STRING));

        $errors = [];
        $superCheck = escapeshellarg("SELECT current_setting('is_superuser')");

        // Official image with POSTGRES_USER != postgres has no postgres role.
        // Peer as the OS postgres user, but login as the volume superuser.
        foreach (array_keys($usernames) as $role) {
            $roleArg = escapeshellarg($role);
            foreach ($databases as $database) {
                $dbArg = escapeshellarg($database);
                try {
                    $isSuper = strtolower(trim($ssh->exec(
                        "cd {$pathArg} && docker compose exec -T -u postgres db "
                        ."psql -U {$roleArg} -d {$dbArg} -Atc {$superCheck}",
                        15
                    )));
                    if ($isSuper === 'on') {
                        return [
                            'username' => $role,
                            'password' => '',
                            'use_password' => false,
                            'as_os_user' => 'postgres',
                            'database' => $database,
                        ];
                    }
                    $errors[] = 'os:postgres as '.$role.'@'.$database.': connected but not superuser';
                } catch (\Throwable $e) {
                    $errors[] = 'os:postgres as '.$role.'@'.$database.': '.$e->getMessage();
                }
            }
        }

        foreach (array_keys($usernames) as $username) {
            $userArg = escapeshellarg($username);

            foreach ($passwords as $password) {
                foreach ($databases as $database) {
                    $dbArg = escapeshellarg($database);
                    try {
                        if ($password === '') {
                            $isSuper = strtolower(trim($ssh->exec(
                                "cd {$pathArg} && docker compose exec -T db "
                                ."psql -U {$userArg} -d {$dbArg} -Atc {$superCheck}",
                                15
                            )));
                            if ($isSuper !== 'on') {
                                $errors[] = $username.'@'.$database.' socket: connected but not superuser';

                                continue;
                            }

                            return [
                                'username' => $username,
                                'password' => '',
                                'use_password' => false,
                                'as_os_user' => null,
                                'database' => $database,
                            ];
                        }

                        $passwordArg = escapeshellarg($password);
                        $isSuper = strtolower(trim($ssh->exec(
                            "cd {$pathArg} && docker compose exec -T -e PGPASSWORD={$passwordArg} db "
                            ."psql -U {$userArg} -d {$dbArg} -Atc {$superCheck}",
                            15
                        )));
                        if ($isSuper !== 'on') {
                            $errors[] = $username.'@'.$database.' password: connected but not superuser';

                            continue;
                        }

                        return [
                            'username' => $username,
                            'password' => $password,
                            'use_password' => true,
                            'as_os_user' => null,
                            'database' => $database,
                        ];
                    } catch (\Throwable $e) {
                        $errors[] = $username.'@'.$database.' '.($password === '' ? 'socket' : 'password').': '.$e->getMessage();
                    }
                }
            }
        }

        throw new \RuntimeException(
            'Could not connect to Postgres as a superuser to repair credentials. '
            .'Tried roles: '.implode(', ', array_keys($usernames)).'. '
            .'Last errors: '.mb_substr(implode(' | ', array_slice($errors, -4)), 0, 600)
        );
    }

    /**
     * Sync MongoDB user credentials inside a running db sidecar.
     *
     * The official mongo image only processes MONGO_INITDB_ROOT_USERNAME/PASSWORD
     * on first init. This method uses mongosh to update or create the user.
     */
    public function syncMongodbSidecarCredentials(
        SSHService $ssh,
        string $containerPath,
        array $envVars
    ): void {
        $pathArg = escapeshellarg($containerPath);
        $username = (string) ($envVars['MONGO_INITDB_ROOT_USERNAME'] ?? $envVars['DB_USERNAME'] ?? 'appuser');
        $password = (string) ($envVars['MONGO_INITDB_ROOT_PASSWORD'] ?? $envVars['DB_PASSWORD'] ?? '');
        $database = (string) ($envVars['MONGO_INITDB_DATABASE'] ?? $envVars['DB_DATABASE'] ?? 'appdb');

        $jsScript = sprintf(
            'db = db.getSiblingDB("admin"); '
            .'try { db.updateUser("%s", { pwd: "%s", roles: [{ role: "root", db: "admin" }] }); } '
            .'catch(e) { db.createUser({ user: "%s", pwd: "%s", roles: [{ role: "root", db: "admin" }] }); } '
            .'db = db.getSiblingDB("%s"); '
            .'try { db.updateUser("%s", { pwd: "%s", roles: [{ role: "dbOwner", db: "%s" }] }); } '
            .'catch(e) { db.createUser({ user: "%s", pwd: "%s", roles: [{ role: "dbOwner", db: "%s" }] }); }',
            addcslashes($username, '"\\'),
            addcslashes($password, '"\\'),
            addcslashes($username, '"\\'),
            addcslashes($password, '"\\'),
            addcslashes($database, '"\\'),
            addcslashes($username, '"\\'),
            addcslashes($password, '"\\'),
            addcslashes($database, '"\\'),
            addcslashes($username, '"\\'),
            addcslashes($password, '"\\'),
            addcslashes($database, '"\\')
        );

        $jsArg = escapeshellarg($jsScript);

        $command = "cd {$pathArg} && ("
            ."docker compose exec -T db mongosh --quiet --eval {$jsArg} 2>/dev/null"
            ." || docker compose exec -T db mongo --quiet --eval {$jsArg} 2>/dev/null"
            .')';

        $ssh->exec($command, 30);
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

        return max(30, min(1800, $timeout));
    }

    private function composeUpTimeoutSeconds($template): int
    {
        return match (strtolower((string) ($template->slug ?? ''))) {
            'ollama' => 1200,
            'erpnext', 'chatwoot', 'odoo' => 600,
            default => 180,
        };
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
            if (strtolower((string) ($template->slug ?? '')) === 'nodejs') {
                // Existing production template rows can predate newly supported
                // official Node tags. Runtime selection must not silently fall
                // back to node:20 after Doctor already selected/pulled Node 22.
                $versions = array_values(array_unique(array_merge(
                    $versions,
                    ContainerTemplate::nodeRuntimeVersions(),
                )));
            }

            if (
                in_array($selectedVersion, $versions, true)
                && strtolower((string) ($template->slug ?? '')) !== 'ollama'
            ) {
                $imageName = explode(':', $dockerImage)[0];

                return $imageName.':'.$selectedVersion;
            }
        }

        return $dockerImage;
    }

    public function composeUpCommand(
        string $containerPath,
        bool $localRuntimeImage,
        bool $useExplicitComposeFile = false,
    ): string {
        $fileFlag = $useExplicitComposeFile ? ' -f docker-compose.yml' : '';
        $pullFlag = $localRuntimeImage ? ' --pull never' : '';

        return "cd {$containerPath} && docker compose{$fileFlag} up -d --remove-orphans{$pullFlag}";
    }

    private function composeUp(
        SSHService $ssh,
        string $containerPath,
        bool $localRuntimeImage,
        bool $useExplicitComposeFile = false,
        int $timeoutSeconds = self::DEPLOY_TIMEOUT,
        ?string $containerName = null,
    ): void {
        $this->ensureSharedDockerNetwork($ssh);

        $command = $this->composeUpCommand($containerPath, $localRuntimeImage, $useExplicitComposeFile);
        $timeoutSeconds = max(self::DEPLOY_TIMEOUT, $timeoutSeconds);
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $ssh->exec($command, $timeoutSeconds);

                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                $message = $e->getMessage();

                if ($this->isDockerAddressPoolExhausted($message)) {
                    $this->ensureSharedDockerNetwork($ssh);

                    continue;
                }

                if ($this->isDockerContainerMarkedForRemoval($message)) {
                    \Log::warning('Docker compose up hit a container still marked for removal; waiting then retrying', [
                        'container_path' => $containerPath,
                        'attempt' => $attempt,
                        'error' => $message,
                    ]);
                    $this->waitForRemovingDockerContainers(
                        $ssh,
                        $containerPath,
                        $this->dockerContainerRefsFromComposeError($message)
                    );

                    continue;
                }

                if ($this->isDockerHostPortAllocated($message)) {
                    $busyPort = $this->dockerHostPortFromBindError($message);
                    \Log::warning('Docker compose up hit a published port still allocated; reclaiming leftovers and retrying', [
                        'container_path' => $containerPath,
                        'port' => $busyPort,
                        'attempt' => $attempt,
                        'error' => $message,
                    ]);
                    if ($busyPort !== null) {
                        $this->reclaimStalePublishedPort(
                            $ssh,
                            $containerPath,
                            $busyPort,
                            $containerName ?: basename(rtrim($containerPath, '/'))
                        );
                    }

                    continue;
                }

                if ($this->isDockerContainerNameConflict($message)) {
                    $project = basename(rtrim($containerPath, '/'));
                    \Log::warning('Docker compose up hit a container name conflict; clearing leftovers and retrying', [
                        'container_path' => $containerPath,
                        'project' => $project,
                        'attempt' => $attempt,
                        'error' => $message,
                    ]);
                    $this->clearDockerComposeNameConflicts($ssh, $project, $message);

                    continue;
                }

                throw $e;
            }
        }

        if ($lastError && $this->isDockerContainerMarkedForRemoval($lastError->getMessage())) {
            throw new \RuntimeException(
                'Docker is still removing a previous copy of this container. Wait a minute, then try Start or Redeploy stack again.',
                0,
                $lastError
            );
        }

        if ($lastError && $this->isDockerHostPortAllocated($lastError->getMessage())) {
            $busyPort = $this->dockerHostPortFromBindError($lastError->getMessage());

            throw new \RuntimeException(
                'Host port'.($busyPort ? ' '.$busyPort : '').' is still in use after removing this stack\'s leftovers. Retry deploy, or ask an operator to free the port.',
                0,
                $lastError
            );
        }

        throw $lastError ?? new \RuntimeException('Docker compose up failed');
    }

    public function isDockerContainerNameConflict(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'conflict. the container name')
            || (str_contains($message, 'already in use by container') && str_contains($message, 'container name'));
    }

    public function isDockerContainerMarkedForRemoval(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'marked for removal')
            || str_contains($message, 'removal in progress');
    }

    /**
     * @return list<string>
     */
    public function dockerContainerRefsFromComposeError(string $message): array
    {
        $refs = $this->conflictingDockerRefsFromError($message);

        if (preg_match_all('/Container\s+(\S+)\s+(?:Creating|Created|Starting|Started|Exited|Error)/', $message, $matches)) {
            foreach ($matches[1] as $name) {
                $refs[] = ltrim((string) $name, '/');
            }
        }

        return array_values(array_unique(array_filter($refs)));
    }

    /**
     * `docker rm -f` and `compose down` mark containers for removal asynchronously.
     * compose up then tries to start the old name and Docker rejects it.
     *
     * @param  list<string>  $extraRefs
     */
    private function waitForRemovingDockerContainers(
        SSHService $ssh,
        string $containerPath,
        array $extraRefs = [],
        int $timeoutSeconds = 90
    ): void {
        $project = basename(rtrim($containerPath, '/'));
        $safeProject = preg_replace('/[^a-zA-Z0-9_.-]/', '', $project) ?: '';
        if ($safeProject === '') {
            return;
        }

        $refs = [$safeProject];
        foreach ($extraRefs as $ref) {
            $clean = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $ref);
            if ($clean !== '') {
                $refs[] = $clean;
            }
        }
        $refs = array_values(array_unique($refs));
        $timeout = max(15, min(180, $timeoutSeconds));

        $script = 'proj='.escapeshellarg($safeProject).'; '
            .'deadline=$(( $(date +%s) + '.$timeout.' )); '
            .'refs='.escapeshellarg(implode(' ', $refs)).'; '
            .'while [ "$(date +%s)" -lt "$deadline" ]; do '
            .'leftover=""; '
            .'ids=$(docker ps -aq --filter label=com.docker.compose.project="$proj" 2>/dev/null || true); '
            .'for ref in $refs; do ids="$ids $(docker ps -aq --filter name="$ref" 2>/dev/null || true)"; done; '
            .'for id in $ids; do '
            .'[ -z "$id" ] && continue; '
            .'status=$(docker inspect --format '.escapeshellarg('{{.State.Status}}').' "$id" 2>/dev/null || true); '
            .'if [ "$status" = "removing" ] || [ "$status" = "dead" ]; then leftover="$leftover $id"; fi; '
            .'done; '
            .'leftover=$(echo "$leftover" | xargs 2>/dev/null || true); '
            .'if [ -z "$leftover" ]; then exit 0; fi; '
            .'echo "$leftover" | xargs -r docker rm -f >/dev/null 2>&1 || true; '
            .'sleep 2; '
            .'done; '
            .'exit 1';

        try {
            $ssh->exec($script, $timeout + 15, false);
        } catch (\Throwable $e) {
            \Log::warning('Docker still had containers marked for removal after waiting', [
                'container_path' => $containerPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isDockerAddressPoolExhausted(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'fully subnetted')
            || str_contains($message, 'all predefined address pools');
    }

    public function isDockerHostPortAllocated(string $message): bool
    {
        return preg_match('/port is already allocated|Bind for 0\.0\.0\.0:\d+ failed/i', $message) === 1;
    }

    public function dockerHostPortFromBindError(string $message): ?int
    {
        if (preg_match('/Bind for 0\.0\.0\.0:(\d+) failed/i', $message, $matches)) {
            $port = (int) $matches[1];

            return $port > 0 && $port <= 65535 ? $port : null;
        }

        return null;
    }

    /**
     * Remove leftover containers from this stack that still publish the host port.
     * Never deletes another customer's container.
     */
    public function reclaimStalePublishedPort(
        SSHService $ssh,
        string $containerPath,
        int $port,
        string $containerName,
    ): void {
        $port = max(1, min(65535, $port));
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '', $containerName) ?: '';
        $safeProject = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename(rtrim($containerPath, '/'))) ?: $safeName;
        if ($safeName === '' || $safeProject === '') {
            return;
        }

        $nameFmt = escapeshellarg('{{.Name}}');
        $labelFmt = escapeshellarg('{{index .Config.Labels "com.docker.compose.project"}}');
        $script = 'port='.escapeshellarg((string) $port).'; '
            .'name='.escapeshellarg($safeName).'; '
            .'proj='.escapeshellarg($safeProject).'; '
            .'ids=$(docker ps -aq --filter publish="$port" 2>/dev/null || true); '
            .'for id in $ids; do '
            .'[ -z "$id" ] && continue; '
            .'cname=$(docker inspect --format '.$nameFmt.' "$id" 2>/dev/null | sed "s#^/##"); '
            .'cproj=$(docker inspect --format '.$labelFmt.' "$id" 2>/dev/null || true); '
            .'if [ "$cname" = "$name" ] || [ "$cname" = "${name}-db" ] || [ "$cname" = "${name}-mysql" ] '
            .'|| [ "$cproj" = "$proj" ] || [ "$cproj" = "$name" ] '
            .'|| echo "$cname" | grep -Eq "^[a-fA-F0-9]+_${proj}$"; then '
            .'docker rm -f "$id" >/dev/null 2>&1 || true; '
            .'fi; '
            .'done; '
            .'docker rm -f "$name" "${name}-db" "${name}-mysql" >/dev/null 2>&1 || true';

        try {
            $ssh->exec($script, 60, false);
        } catch (\Throwable $e) {
            \Log::warning('Failed to reclaim stale published port bindings', [
                'container_path' => $containerPath,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function publishedPortIsFree(SSHService $ssh, int $port): bool
    {
        $port = max(1, min(65535, $port));
        $ids = trim($ssh->exec(
            'docker ps -q --filter publish='.escapeshellarg((string) $port).' 2>/dev/null || true',
            15,
            false
        ));

        return $ids === '';
    }

    private function ensurePublishedPortIsAvailable(
        SSHService $ssh,
        Node $node,
        ContainerDeployment $deployment,
        int $port,
        string $containerName,
        string $containerPath,
    ): int {
        $busyPorts = [];

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->reclaimStalePublishedPort($ssh, $containerPath, $port, $containerName);
            if ($this->publishedPortIsFree($ssh, $port)) {
                return $port;
            }

            $busyPorts[] = $port;
            $port = DB::transaction(function () use ($node, $deployment, $busyPorts) {
                $lockedNode = Node::whereKey($node->id)->lockForUpdate()->firstOrFail();

                return $this->assignPort($lockedNode, null, [(int) $deployment->id], $busyPorts);
            });

            \Log::warning('Published host port still allocated; reserved a replacement', [
                'container' => $containerName,
                'replacement_port' => $port,
                'blocked_ports' => $busyPorts,
            ]);
        }

        return $port;
    }

    /**
     * Join the host-wide bridge instead of allocating a new subnet per site.
     */
    public function ensureSharedDockerNetwork(SSHService $ssh): void
    {
        $name = self::SHARED_DOCKER_NETWORK;
        $quoted = escapeshellarg($name);

        try {
            $ssh->exec('docker network inspect '.$quoted, 15);

            return;
        } catch (\Throwable) {
        }

        try {
            $ssh->exec('docker network create --driver bridge '.$quoted, 30);
        } catch (\Throwable $e) {
            if (! $this->isDockerAddressPoolExhausted($e->getMessage())) {
                throw $e;
            }

            $ssh->exec(
                'docker network create --driver bridge --subnet 10.201.0.0/16 '.$quoted,
                30
            );
        }
    }

    /**
     * @return list<string>
     */
    public function conflictingDockerRefsFromError(string $message): array
    {
        $refs = [];

        if (preg_match_all('/container name\s+"\/?([^"]+)"/i', $message, $nameMatches)) {
            foreach ($nameMatches[1] as $name) {
                $refs[] = ltrim((string) $name, '/');
            }
        }

        if (preg_match_all('/already in use by container\s+"([a-f0-9]{12,64})"/i', $message, $idMatches)) {
            foreach ($idMatches[1] as $id) {
                $refs[] = (string) $id;
            }
        }

        return array_values(array_unique(array_filter($refs)));
    }

    private function clearDockerComposeNameConflicts(
        SSHService $ssh,
        string $projectName,
        string $errorMessage
    ): void {
        $refs = $this->conflictingDockerRefsFromError($errorMessage);

        foreach ($refs as $ref) {
            $refArg = escapeshellarg($ref);
            @$ssh->exec("docker rm -f {$refArg} 2>/dev/null || true", 30);
        }

        // Compose often leaves "<hash>_<project>" rename leftovers after a failed recreate.
        // Only remove those exact leftover names — never sidecars like "<project>-mysql".
        $safeProject = preg_replace('/[^a-zA-Z0-9_.-]/', '', $projectName) ?: '';
        if ($safeProject === '') {
            return;
        }

        $script = 'proj='.escapeshellarg($safeProject).'; '
            .'docker ps -a --format "{{.ID}} {{.Names}}" 2>/dev/null | while read -r id name; do '
            .'echo "$name" | grep -Eq "^[a-fA-F0-9]+_${proj}$" && docker rm -f "$id" 2>/dev/null || true; '
            .'done';

        @$ssh->exec($script, 60);
    }

    /**
     * Start the compose stack, building Talksasa runtime images locally when required.
     */
    public function startComposeStack(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        bool $recreate = false
    ): void {
        $this->ensureComposeFileExists($ssh, $deployment);
        $this->rescueShadowedWordPressContent($ssh, $deployment);
        $this->refreshComposeYamlIfStale($ssh, $service, $deployment);

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        // Clean up any stale credential repair container that may block volume access
        @$ssh->exec('docker rm -f db_credential_repair 2>/dev/null', 10);

        $service->loadMissing('product.containerTemplate');
        $template = $this->resolveContainerTemplate($service) ?? $service->product?->containerTemplate;
        $usesRuntimeImage = $template && $this->runtimeImages->usesRuntimeImage($template);

        if ($usesRuntimeImage) {
            $this->runtimeImages->ensureImage($ssh, $template, $deployment->selected_version, $service, $deployment);
        }

        $hasNextSidecars = $this->usesLaravelNextSidecarStack($deployment)
            || str_contains((string) $deployment->docker_compose_content, "\n  frontend:\n");

        if ($hasNextSidecars) {
            // Rewrite compose when older files still interpolate $BACKEND_DIR on the host.
            $this->syncLaravelNextSidecarComposeFile($ssh, $service, $deployment);
            $deployment->refresh();
            $this->ensureNextSidecarImages($ssh);
        }

        if (($template?->slug ?? '') === 'wordpress') {
            $this->wordpressHardening->ensureUploadsIniFile($ssh, $deployment->container_name);
        }

        if ($recreate) {
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down --remove-orphans", self::DEPLOY_TIMEOUT);
            $this->waitForRemovingDockerContainers($ssh, $containerPath);
        }

        $this->composeUp($ssh, $containerPath, (bool) $usesRuntimeImage, useExplicitComposeFile: true, containerName: $deployment->container_name);
        $this->syncPhpExtensionsIfSupported($ssh, $service, $deployment);
        $this->syncDatabaseCredentialsAfterStart($ssh, $service, $deployment, $containerPath);

        $service->loadMissing('product.containerTemplate');
        if (($this->resolveContainerTemplate($service)?->slug ?? '') === 'wordpress') {
            $this->wordpressHardening->hardenDeployedStack(
                $ssh,
                $service->fresh(['product.containerTemplate', 'containerDeployment']),
                $deployment->container_name,
                $containerPath
            );
        }
    }

    /**
     * Older WordPress stacks mounted a named volume over /var/www/html/wp-content, so
     * uploads, themes and plugins live inside Docker instead of the application directory.
     * Copy them onto the bind mount before the stack restarts without that volume,
     * otherwise the site comes back with an empty media library.
     */
    private function rescueShadowedWordPressContent(SSHService $ssh, ContainerDeployment $deployment): void
    {
        if (! str_contains((string) $deployment->docker_compose_content, 'wp_content:/var/www/html/wp-content')) {
            return;
        }

        $hostContentPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app/wp-content';

        try {
            $volume = $this->resolveComposeVolumeName($ssh, $deployment->container_name, 'wp_content');
            if ($volume === null) {
                return;
            }

            $mountpoint = trim($ssh->exec(
                'docker volume inspect -f '.escapeshellarg('{{.Mountpoint}}').' '.escapeshellarg($volume).' 2>/dev/null || true',
                20
            ));

            if ($mountpoint === '' || ! str_starts_with($mountpoint, '/')) {
                return;
            }

            // -n keeps anything already on the bind mount; the volume copy is only a fallback.
            $ssh->exec(
                'mkdir -p '.escapeshellarg($hostContentPath)
                .' && cp -an '.escapeshellarg($mountpoint.'/.').' '.escapeshellarg($hostContentPath.'/').' 2>/dev/null || true'
                .' && chown -R 33:33 '.escapeshellarg($hostContentPath),
                300,
                false
            );

            Log::info('Recovered WordPress wp-content from shadowed named volume', [
                'deployment_id' => $deployment->id,
                'volume' => $volume,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to recover shadowed WordPress wp-content volume', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveComposeVolumeName(SSHService $ssh, string $containerName, string $volumeKey): ?string
    {
        $suffix = '_'.$volumeKey;
        $names = trim($ssh->exec(
            'docker volume ls --format '.escapeshellarg('{{.Name}}').' 2>/dev/null || true',
            20
        ));

        $candidates = array_values(array_filter(
            preg_split("/\r\n|\n|\r/", $names) ?: [],
            fn ($name) => str_ends_with(trim($name), $suffix)
        ));

        $project = strtolower($containerName);
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (strtolower($candidate) === $project.$suffix) {
                return $candidate;
            }
        }

        // Compose strips characters the project name cannot contain; fall back to a
        // relaxed comparison rather than leaving customer media behind.
        $normalized = preg_replace('/[^a-z0-9]/', '', $project);
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            $candidateProject = preg_replace('/[^a-z0-9]/', '', strtolower(substr($candidate, 0, -strlen($suffix))));
            if ($candidateProject === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    private function syncDatabaseCredentialsAfterStart(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        string $containerPath
    ): void {
        $databaseTemplate = $this->resolveDatabaseTemplateForService($service);
        if (! $databaseTemplate) {
            return;
        }

        $envValues = is_array($deployment->env_values) ? $deployment->env_values : [];

        try {
            $this->waitForDatabaseSidecar($ssh, $containerPath, $databaseTemplate, $envValues, 60);
        } catch (\Throwable $e) {
            \Log::warning('Database credential sync after start skipped: DB not ready', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncPhpExtensionsIfSupported(SSHService $ssh, Service $service, ContainerDeployment $deployment): void
    {
        $extensions = app(ContainerPhpExtensionsService::class);
        if (! $extensions->supportsTemplate($service->product?->containerTemplate?->slug)) {
            return;
        }

        try {
            $this->waitForContainerRunning($ssh, $deployment->container_name, 60);
            $extensions->syncEnabledExtensions($service, $deployment, $ssh);
            app(LaravelAppInitializationService::class)->ensureNodeRuntime($ssh, $deployment);
        } catch (\Throwable $e) {
            \Log::warning('Failed to sync enabled PHP extensions', [
                'service_id' => $service->id,
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function installLaravelFrontendAfterDeploy(SSHService $ssh, Service $service, ContainerDeployment $deployment): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        // Project recipe deploys Next as a separate service — never attach Compose sidecars.
        if (! empty($meta['project_recipe'])) {
            return;
        }

        $slug = $this->resolveContainerTemplate($service)?->slug
            ?? $service->product?->containerTemplate?->slug
            ?? '';
        if (! in_array($slug, ['laravel', 'php'], true)) {
            return;
        }

        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $messages = $this->stackCommands->installLaravelFrontendDependencies(
            $ssh,
            $deployment,
            $hostAppPath,
            (int) config('containers.node_build.command_timeout_seconds', 900),
            forceRebuild: true
        );

        if ($messages !== []) {
            $this->recordDeploymentEvent($service, $deployment, 'laravel_frontend_prepared', [
                'messages' => $messages,
            ]);
        }

        if (! $this->stackCommands->hostHasNextFrontend($ssh, $hostAppPath)) {
            return;
        }

        try {
            $this->switchLaravelRuntimeToNextFrontend($ssh, $service, $deployment, $hostAppPath);
        } catch (\Throwable $e) {
            \Log::warning('Failed to switch Laravel public runtime to Next.js frontend', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After a Git pull / frontend build: keep PHP on backend, refresh the Node frontend sidecar.
     * Domains stay on this Talksasa service (assigned_port → edge).
     */
    public function refreshLaravelNextFrontendRuntime(SSHService $ssh, Service $service, ContainerDeployment $deployment): void
    {
        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        if (! $this->stackCommands->hostHasNextFrontend($ssh, $hostAppPath)) {
            return;
        }

        if ($this->usesLaravelNextSidecarStack($deployment)) {
            $this->syncLaravelNextSidecarComposeFile($ssh, $service, $deployment);
            $deployment->refresh();
            $this->ensureNextSidecarImages($ssh);
            $this->restartLaravelNextFrontendServices($ssh, $deployment);

            return;
        }

        $this->switchLaravelRuntimeToNextFrontend($ssh, $service, $deployment, $hostAppPath);
    }

    /**
     * Restart only the Node frontend (and edge) after a build — backend/PHP stays up.
     */
    public function restartLaravelNextFrontendServices(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $pathArg = escapeshellarg($containerPath);

        $this->ensureNextSidecarImages($ssh);

        // Bring frontend/edge up without recreating the PHP backend when possible.
        @$ssh->exec(
            "cd {$pathArg} && docker compose -f docker-compose.yml up -d --no-deps --pull never "
            .escapeshellarg(LaravelNextGatewayProxy::FRONTEND_SERVICE).' '
            .escapeshellarg(LaravelNextGatewayProxy::EDGE_SERVICE),
            self::DEPLOY_TIMEOUT
        );

        @$ssh->exec(
            "cd {$pathArg} && docker compose -f docker-compose.yml restart "
            .escapeshellarg(LaravelNextGatewayProxy::FRONTEND_SERVICE).' '
            .escapeshellarg(LaravelNextGatewayProxy::EDGE_SERVICE),
            self::DEPLOY_TIMEOUT
        );
    }

    private function switchLaravelRuntimeToNextFrontend(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
        string $hostAppPath
    ): void {
        $service->loadMissing('product.containerTemplate');
        $template = $service->product?->containerTemplate;
        if (! $template) {
            return;
        }

        $relativeDir = $this->stackCommands->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath) ?? 'frontend';
        $documentRoot = app(LaravelProjectPathResolver::class)->resolveDocumentRoot($ssh, $hostAppPath) ?: '/app/public';

        $gatewayHostPath = LaravelNextGatewayProxy::hostScriptPath($hostAppPath);
        $ssh->upload(
            LaravelNextGatewayProxy::scriptContents(
                LaravelNextGatewayProxy::EDGE_INTERNAL_PORT,
                LaravelNextGatewayProxy::BACKEND_PORT,
                LaravelNextGatewayProxy::FRONTEND_PORT,
            ),
            $gatewayHostPath
        );

        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $envVars['HOME'] = '/tmp';
        $envVars['NPM_CONFIG_CACHE'] = '/tmp/.npm';
        $envVars['npm_config_cache'] = '/tmp/.npm';
        $envVars['CACHE_STORE'] = $envVars['CACHE_STORE'] ?? 'file';
        $envVars['CACHE_DRIVER'] = $envVars['CACHE_DRIVER'] ?? 'file';
        $envVars['INTERNAL_API_URL'] = 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.LaravelNextGatewayProxy::BACKEND_PORT;
        $envVars['BACKEND_URL'] = $envVars['INTERNAL_API_URL'];
        $envVars['LARAVEL_API_PORT'] = (string) LaravelNextGatewayProxy::BACKEND_PORT;
        $envVars['NEXT_PORT'] = (string) LaravelNextGatewayProxy::FRONTEND_PORT;

        $publicUrl = $this->resolvePublicAppUrl($deployment, $envVars);
        if ($publicUrl !== null) {
            $envVars['APP_URL'] = $publicUrl;
            $envVars['FRONTEND_URL'] = $publicUrl;
            $envVars['NEXT_PUBLIC_APP_URL'] = $publicUrl;
            $envVars['NEXT_PUBLIC_API_URL'] = rtrim($publicUrl, '/').'/api/v1';
            $envVars['API_URL'] = $envVars['NEXT_PUBLIC_API_URL'];
        }

        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            $this->resolveDatabaseTemplateForService($service),
            $deployment,
            $deployment->selected_version,
            $hostAppPath,
            null,
            $documentRoot,
            serveNextFrontend: true,
            nextFrontendRelativeDir: $relativeDir,
            laravelApiPort: LaravelNextGatewayProxy::BACKEND_PORT,
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');
        $deployment->update([
            'docker_compose_content' => $composeYaml,
            'env_values' => $envVars,
        ]);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['env_values'] = $envVars;
        $meta['frontend'] = $meta['frontend'] ?? 'nextjs';
        $service->update(['service_meta' => $meta]);

        // Runtime Laravel images use --pull never; Node sidecars must be pulled explicitly.
        $this->ensureNextSidecarImages($ssh);

        @$ssh->exec(
            'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml down --remove-orphans',
            self::DEPLOY_TIMEOUT
        );

        $this->composeUp(
            $ssh,
            $containerPath,
            $this->runtimeImages->usesRuntimeImage($template),
            useExplicitComposeFile: true
        );

        $this->waitForLaravelNextSidecarHealth($ssh, $deployment->container_name, 180);

        $this->recordDeploymentEvent($service, $deployment, 'laravel_next_sidecar_stack', [
            'frontend_dir' => $relativeDir,
            'public_port' => (int) $deployment->assigned_port,
            'backend_port' => LaravelNextGatewayProxy::BACKEND_PORT,
            'frontend_port' => LaravelNextGatewayProxy::FRONTEND_PORT,
            'edge_port' => LaravelNextGatewayProxy::EDGE_INTERNAL_PORT,
        ]);
    }

    public function ensureNextSidecarImagesPublic(SSHService $ssh): void
    {
        $this->ensureNextSidecarImages($ssh);
    }

    private function ensureNextSidecarImages(SSHService $ssh): void
    {
        $images = array_unique(array_filter([
            $this->nextSidecarImage('node_image', 'node:20-bookworm-slim'),
            $this->nextSidecarImage('edge_image', 'node:20-alpine'),
        ]));

        foreach ($images as $image) {
            $safe = escapeshellarg($image);
            $present = trim($ssh->exec(
                'docker image inspect '.$safe.' >/dev/null 2>&1 && echo yes || echo no',
                30
            ));
            if ($present === 'yes') {
                continue;
            }

            \Log::info('Pulling Next sidecar image', ['image' => $image]);
            try {
                $ssh->exec('docker pull '.$safe, 600);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Failed to pull Next sidecar image '.$image.'. '
                    .'The node needs outbound Docker Hub access (compose uses --pull never for Laravel runtime images). '
                    .$e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function ensureNodeWebSidecarImages(SSHService $ssh, string $frontendType): void
    {
        $images = ['node:22-alpine'];
        if ($frontendType === 'vite-spa') {
            $images[] = 'nginx:1.27-alpine';
        }
        foreach ($images as $image) {
            $safe = escapeshellarg($image);
            if (trim($ssh->exec(
                'docker image inspect '.$safe.' >/dev/null 2>&1 && echo yes || echo no',
                30,
            )) !== 'yes') {
                $ssh->exec('docker pull '.$safe, 600);
            }
        }
    }

    /**
     * Re-upload sidecar compose + gateway script (no stack recreate).
     */
    private function syncLaravelNextSidecarComposeFile(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment
    ): void {
        $service->loadMissing('product.containerTemplate');
        $template = $service->product?->containerTemplate;
        if (! $template || ($template->slug ?? null) !== 'laravel') {
            return;
        }

        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        if (! $this->stackCommands->hostHasNextFrontend($ssh, $hostAppPath)
            && ! $this->usesLaravelNextSidecarStack($deployment)) {
            return;
        }

        $relativeDir = $this->stackCommands->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath) ?? 'frontend';
        $documentRoot = app(LaravelProjectPathResolver::class)->resolveDocumentRoot($ssh, $hostAppPath) ?: '/app/public';

        $ssh->upload(
            LaravelNextGatewayProxy::scriptContents(
                LaravelNextGatewayProxy::EDGE_INTERNAL_PORT,
                LaravelNextGatewayProxy::BACKEND_PORT,
                LaravelNextGatewayProxy::FRONTEND_PORT,
            ),
            LaravelNextGatewayProxy::hostScriptPath($hostAppPath)
        );

        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $envVars['INTERNAL_API_URL'] = 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.LaravelNextGatewayProxy::BACKEND_PORT;
        $envVars['BACKEND_URL'] = $envVars['INTERNAL_API_URL'];
        $publicUrl = $this->resolvePublicAppUrl($deployment, $envVars);
        if ($publicUrl !== null) {
            $envVars['APP_URL'] = $envVars['APP_URL'] ?? $publicUrl;
            $envVars['FRONTEND_URL'] = $envVars['FRONTEND_URL'] ?? $publicUrl;
            $envVars['NEXT_PUBLIC_APP_URL'] = $envVars['NEXT_PUBLIC_APP_URL'] ?? $publicUrl;
            $envVars['NEXT_PUBLIC_API_URL'] = $envVars['NEXT_PUBLIC_API_URL'] ?? (rtrim($publicUrl, '/').'/api/v1');
            $envVars['API_URL'] = $envVars['API_URL'] ?? $envVars['NEXT_PUBLIC_API_URL'];
        }

        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            $this->resolveDatabaseTemplateForService($service),
            $deployment,
            $deployment->selected_version,
            $hostAppPath,
            null,
            $documentRoot,
            serveNextFrontend: true,
            nextFrontendRelativeDir: $relativeDir,
            laravelApiPort: LaravelNextGatewayProxy::BACKEND_PORT,
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');
        $deployment->update([
            'docker_compose_content' => $composeYaml,
            'env_values' => $envVars,
        ]);
    }

    /**
     * Backend keeps the deployment container_name; edge is required for public traffic.
     */
    private function waitForLaravelNextSidecarHealth(
        SSHService $ssh,
        string $backendContainerName,
        int $timeoutSeconds = 180
    ): void {
        $edgeName = LaravelNextGatewayProxy::edgeContainerName($backendContainerName);
        $deadline = time() + max(30, $timeoutSeconds);
        $lastError = null;

        while (time() < $deadline) {
            try {
                $backend = $this->getContainerStatus($ssh, $backendContainerName);
                $edge = $this->getContainerStatus($ssh, $edgeName);

                if (($backend['running'] ?? false) && ($edge['running'] ?? false)) {
                    return;
                }

                $lastError = sprintf(
                    'backend running=%s state=%s; edge running=%s state=%s',
                    ($backend['running'] ?? false) ? 'yes' : 'no',
                    (string) ($backend['state'] ?? 'unknown'),
                    ($edge['running'] ?? false) ? 'yes' : 'no',
                    (string) ($edge['state'] ?? 'unknown'),
                );
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            sleep(self::HEALTH_CHECK_DELAY);
        }

        throw new \RuntimeException(
            'Laravel Next sidecar stack failed to become healthy after '.$timeoutSeconds.' seconds'
            .($lastError ? ' ('.$lastError.')' : '.')
        );
    }

    private function resolveHostAppPath($template, string $containerName): ?string
    {
        $path = self::CONTAINER_BASE_PATH.'/'.$containerName.'/app';
        $slug = $template->slug ?? '';

        if (! isset($template->volume_paths) || ! is_array($template->volume_paths)) {
            // Legacy template rows may miss volume_paths; still keep app path
            // for runtime templates that expect /app content.
            if (in_array($slug, ['laravel', 'php', 'nodejs', 'python', 'ruby', 'go', 'wordpress'], true)) {
                return $path;
            }

            return null;
        }

        if (array_key_exists('app_data', $template->volume_paths)
            || array_key_exists('wp_data', $template->volume_paths)
            || array_key_exists('web_root', $template->volume_paths)
            || $slug === 'wordpress'
            || $slug === 'static-site') {
            return $path;
        }

        if (in_array($slug, ['laravel', 'php', 'nodejs', 'python', 'ruby', 'go'], true)) {
            return $path;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $nodeTopology
     */
    private function resolveApplicationRuntime(
        SSHService $ssh,
        $template,
        ?string $hostAppPath,
        ?array $nodeTopology = null,
    ): ?ApplicationRuntime {
        if (! $hostAppPath || ! $this->applicationRuntime->supportsTemplate($template->slug ?? null)) {
            return null;
        }

        $pinned = $this->pinnedNodeBackendRoot($nodeTopology);
        if ($this->supportsSplitWebWorkloads($template->slug ?? null) && $pinned !== null) {
            $port = ($nodeTopology['topology'] ?? null) === 'split_web_api'
                ? ContainerNodeWorkloadTopologyService::BACKEND_PORT
                : (int) ($template->default_port ?? 3000);

            return $this->applicationRuntime->detectRuntimeAt(
                $ssh,
                $hostAppPath,
                $pinned,
                (string) $template->slug,
                $port,
                includeBootstrap: false,
            );
        }

        return $this->applicationRuntime->detectFromHost(
            $ssh,
            $hostAppPath,
            (string) $template->slug,
            (int) ($template->default_port ?? 3000),
            includeNodeBootstrap: ($template->slug ?? null) !== 'nodejs',
        );
    }

    /**
     * @param  array<string, mixed>|null  $nodeTopology
     */
    private function pinnedNodeBackendRoot(?array $nodeTopology): ?string
    {
        $root = data_get($nodeTopology, 'backend.root');

        return is_string($root) && trim($root) !== '' ? $root : null;
    }

    /**
     * @param  array<string, mixed>  $rollback
     */
    private function rollbackNodeRedeploy(
        Service $service,
        ContainerDeployment $deployment,
        Node $node,
        string $containerName,
        array $rollback,
        bool $cutoverStarted,
    ): void {
        if (($rollback['compose'] ?? '') === '') {
            throw new \RuntimeException('Previous Compose configuration is unavailable.');
        }
        $ssh = SSHService::forNode($node);
        try {
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;
            if ($cutoverStarted) {
                $this->tearDownStack($ssh, $containerPath, removeVolumes: false);
            }
            $ssh->upload((string) $rollback['compose'], $containerPath.'/docker-compose.yml');
            if (is_array($rollback['node_release'] ?? null)) {
                $hostAppPath = $this->appDirectory->hostAppPath($deployment);
                $ssh->mkdirp($hostAppPath.'/.talksasa');
                $ssh->upload(
                    json_encode($rollback['node_release'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
                    $hostAppPath.'/'.ContainerNodeBuildService::MANIFEST_RELATIVE_PATH,
                );
            }
            if ($cutoverStarted) {
                $this->composeUp($ssh, $containerPath, true, useExplicitComposeFile: true);
                $oldSplit = str_contains((string) $rollback['compose'], "\n  backend:\n")
                    && str_contains((string) $rollback['compose'], "\n  frontend:\n")
                    && str_contains((string) $rollback['compose'], "\n  edge:\n");
                if ($oldSplit) {
                    $this->waitForNodeSplitStackReadiness($ssh, $deployment, 120);
                } else {
                    $this->waitForNodeApplicationReadiness($ssh, $deployment, 120);
                }
            }
            $deployment->update([
                'docker_compose_content' => $rollback['compose'],
                'status' => $rollback['deployment_status'] ?? 'running',
            ]);
            $service->update(['status' => $rollback['service_status'] ?? 'active']);
        } finally {
            $ssh->disconnect();
        }
    }

    private function resolveNodeVersionForDeployment(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
    ): ?string {
        $resolution = app(ContainerNodeVersionService::class)->reconcileFromHost(
            $service,
            $deployment,
            $ssh,
        );
        $selectedVersion = $resolution['selected_version'];
        if ($deployment->selected_version !== $selectedVersion) {
            $deployment->update(['selected_version' => $selectedVersion]);
        }
        $this->recordDeploymentEvent($service, $deployment, 'node_version_resolved', [
            'selected_version' => $selectedVersion,
            'source' => $resolution['source'],
            'constraint' => $resolution['constraint'],
            'changed' => $resolution['changed'],
        ]);

        return $selectedVersion;
    }

    /**
     * @param  array<string, mixed>  $topology
     */
    public function resolveSplitNodeVersion(
        Service $service,
        ContainerDeployment $deployment,
        $template,
        array $topology,
        ?string $current,
    ): ?string {
        if (($topology['topology'] ?? null) !== 'split_web_api') {
            return $current;
        }
        $constraints = array_values(array_filter([
            data_get($topology, 'backend.node_engine'),
            data_get($topology, 'frontend.node_engine'),
        ], fn ($value) => is_string($value) && trim($value) !== ''));
        if ($constraints === []) {
            return $current;
        }

        $versionService = app(ContainerNodeVersionService::class);
        $allowed = method_exists($template, 'nodeRuntimeVersions')
            ? $template::nodeRuntimeVersions()
            : [];
        $compatible = array_values(array_filter($allowed, function (string $version) use ($constraints, $versionService): bool {
            $major = $versionService->majorFromVersion($version);

            return $major !== null
                && collect($constraints)->every(fn (string $constraint): bool => $versionService->majorSatisfies($major, $constraint));
        }));
        if ($compatible === []) {
            throw new \DomainException(
                'Backend and frontend Node engine requirements are incompatible with the platform runtimes: '
                .implode(' and ', $constraints).'.'
            );
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $manual = ($meta['node_version_source'] ?? 'auto') === 'manual';
        $currentMajor = $versionService->majorFromVersion($current);
        if ($manual) {
            if ($currentMajor === null || ! collect($constraints)->every(
                fn (string $constraint): bool => $versionService->majorSatisfies($currentMajor, $constraint)
            )) {
                throw new \DomainException(
                    'The manually selected Node runtime does not satisfy both workloads ('.implode(' and ', $constraints).').'
                );
            }

            return $current;
        }

        $selected = in_array($current, $compatible, true) ? $current : $compatible[0];
        if ($deployment->selected_version !== $selected) {
            $deployment->update(['selected_version' => $selected]);
        }
        $meta['selected_version'] = $selected;
        $meta['node_detected_engine'] = implode(' + ', $constraints);
        $meta['node_detected_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);

        return $selected;
    }

    public function refreshApplicationRuntimeCompose(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $service->loadMissing('product.containerTemplate');
        $template = $this->resolveContainerTemplate($service);

        if (! $template || ! $this->applicationRuntime->supportsTemplate($template->slug)) {
            return '';
        }

        $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name);
        if (! $hostAppPath) {
            return '';
        }

        $databaseTemplate = $this->resolveDatabaseTemplate($service, $template);
        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $nodeTopology = null;
        if (($template->slug ?? '') === 'nodejs') {
            unset($envVars['NPM_CONFIG_PRODUCTION'], $envVars['npm_config_production']);
        }
        if ($this->supportsSplitWebWorkloads($template->slug ?? null)) {
            $service->refresh();
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $nodeTopology = app(ContainerNodeWorkloadTopologyService::class)->resolve(
                $service,
                $ssh,
                $hostAppPath,
                is_string($meta['node_backend_root'] ?? null) ? $meta['node_backend_root'] : null,
                is_string($meta['node_frontend_root'] ?? null) ? $meta['node_frontend_root'] : null,
            );
            app(ContainerNodeWorkloadTopologyService::class)->persist($service, $nodeTopology);
            if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                $envVars['INTERNAL_API_URL'] ??= 'http://backend:'.ContainerNodeWorkloadTopologyService::BACKEND_PORT;
                $envVars['BACKEND_URL'] ??= $envVars['INTERNAL_API_URL'];
                $envVars['NEXT_PUBLIC_API_URL'] ??= '/api';
                $envVars['VITE_API_URL'] ??= '/api';
                $ssh->upload(NodeWebGatewayProxy::scriptContents(), NodeWebGatewayProxy::scriptPath($hostAppPath));
                if (($nodeTopology['frontend_type'] ?? '') === 'vite-spa') {
                    $ssh->upload(NodeWebGatewayProxy::viteConfig(), NodeWebGatewayProxy::viteConfigPath($hostAppPath));
                }
                $this->ensureNodeWebSidecarImages($ssh, (string) ($nodeTopology['frontend_type'] ?? 'nextjs'));
            }
            $deployment->update(['env_values' => $envVars]);
        }
        $runtime = $this->resolveApplicationRuntime($ssh, $template, $hostAppPath, $nodeTopology);
        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            $databaseTemplate,
            $deployment,
            $deployment->selected_version,
            $hostAppPath,
            $runtime,
            nodeTopology: $nodeTopology,
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deployment->update(['docker_compose_content' => $composeYaml]);
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

        if (($template->slug ?? '') === 'nodejs') {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['node_project_root'] = $this->pinnedNodeBackendRoot($nodeTopology)
                ?? ($runtime->containerWorkdir === '/app'
                    ? ''
                    : trim(substr($runtime->containerWorkdir, strlen('/app')), '/'));
            $service->update(['service_meta' => $meta]);
        }

        if ($this->runtimeImages->usesRuntimeImage($template)) {
            $this->runtimeImages->ensureImage($ssh, $template, $deployment->selected_version, $service, $deployment);
        }

        if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
            $this->composeUp(
                $ssh,
                $containerPath,
                $this->runtimeImages->usesRuntimeImage($template),
                useExplicitComposeFile: true,
            );
            $this->waitForNodeSplitStackReadiness($ssh, $deployment->fresh(), 180);
        } else {
            $this->restartAppService($ssh, $deployment->fresh());
        }

        return 'Application start command updated ('.$runtime->label.').';
    }

    /**
     * Rewrite compose from current env_values, sync .env when applicable, and recreate the stack.
     */
    public function applyEnvironmentVariables(Service $service, ContainerDeployment $deployment): void
    {
        $operationLock = null;
        $cutoverStarted = false;
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment ?? $deployment;

        if (! $deployment?->node) {
            throw new \DomainException('Container host is not available.');
        }

        $template = $this->resolveContainerTemplate($service);
        if (! $template) {
            throw new \DomainException('Container template is missing.');
        }
        if (($template->slug ?? '') === 'nodejs') {
            $operationLock = Cache::lock(
                app(ContainerNodeBuildService::class)->lockName($service),
                (int) config('containers.node_build.operation_lock_seconds', 1800),
            );
            $operationLock->block(10);
        }

        try {
            $ssh = SSHService::forNode($deployment->node);
        } catch (\Throwable $e) {
            $operationLock?->release();
            throw $e;
        }
        $previousCompose = (string) ($deployment->docker_compose_content ?? '');
        $previousRelease = data_get($service->service_meta, 'node_release');

        try {
            $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name);
            $databaseTemplate = $this->resolveDatabaseTemplate($service, $template);
            $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];

            $runtime = $this->resolveApplicationRuntime($ssh, $template, $hostAppPath);
            $documentRoot = null;
            $serveNextFrontend = false;
            $nodeTopology = null;
            $nextFrontendRelativeDir = 'frontend';

            if (($template->slug ?? null) === 'laravel' && $hostAppPath) {
                $resolver = app(LaravelProjectPathResolver::class);
                if ($resolver->hasProject($ssh, $hostAppPath)) {
                    $resolved = $resolver->persistResolvedPaths($service, $ssh, $deployment);
                    $documentRoot = $resolved['document_root'] ?? $resolver->resolveDocumentRoot($ssh, $hostAppPath);
                }

                $serveNextFrontend = $this->usesLaravelNextSidecarStack($deployment)
                    || $this->stackCommands->hostHasNextFrontend($ssh, $hostAppPath);
                if ($serveNextFrontend) {
                    $nextFrontendRelativeDir = $this->stackCommands->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath) ?? 'frontend';
                    $ssh->upload(
                        LaravelNextGatewayProxy::scriptContents(
                            LaravelNextGatewayProxy::EDGE_INTERNAL_PORT,
                            LaravelNextGatewayProxy::BACKEND_PORT,
                            LaravelNextGatewayProxy::FRONTEND_PORT,
                        ),
                        LaravelNextGatewayProxy::hostScriptPath($hostAppPath)
                    );
                    $publicUrl = $this->resolvePublicAppUrl($deployment, $envVars);
                    $envVars['INTERNAL_API_URL'] = 'http://'.LaravelNextGatewayProxy::BACKEND_SERVICE.':'.LaravelNextGatewayProxy::BACKEND_PORT;
                    $envVars['BACKEND_URL'] = $envVars['INTERNAL_API_URL'];
                    if ($publicUrl !== null) {
                        $envVars['APP_URL'] = $envVars['APP_URL'] ?? $publicUrl;
                        $envVars['FRONTEND_URL'] = $envVars['FRONTEND_URL'] ?? $publicUrl;
                        $envVars['NEXT_PUBLIC_APP_URL'] = $envVars['NEXT_PUBLIC_APP_URL'] ?? $publicUrl;
                        $envVars['NEXT_PUBLIC_API_URL'] = $envVars['NEXT_PUBLIC_API_URL'] ?? (rtrim($publicUrl, '/').'/api/v1');
                        $envVars['API_URL'] = $envVars['API_URL'] ?? $envVars['NEXT_PUBLIC_API_URL'];
                    }
                    $deployment->update(['env_values' => $envVars]);
                }
            }
            if ($this->supportsSplitWebWorkloads($template->slug ?? null) && $hostAppPath) {
                $service->refresh();
                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                $nodeTopology = app(ContainerNodeWorkloadTopologyService::class)->resolve(
                    $service,
                    $ssh,
                    $hostAppPath,
                    is_string($meta['node_backend_root'] ?? null) ? $meta['node_backend_root'] : null,
                    is_string($meta['node_frontend_root'] ?? null) ? $meta['node_frontend_root'] : null,
                );
                app(ContainerNodeWorkloadTopologyService::class)->persist($service, $nodeTopology);
                if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                    $envVars['INTERNAL_API_URL'] ??= 'http://backend:'.ContainerNodeWorkloadTopologyService::BACKEND_PORT;
                    $envVars['BACKEND_URL'] ??= $envVars['INTERNAL_API_URL'];
                    $envVars['NEXT_PUBLIC_API_URL'] ??= '/api';
                    $envVars['VITE_API_URL'] ??= '/api';
                    $ssh->upload(NodeWebGatewayProxy::scriptContents(), NodeWebGatewayProxy::scriptPath($hostAppPath));
                    if (($nodeTopology['frontend_type'] ?? '') === 'vite-spa') {
                        $ssh->upload(NodeWebGatewayProxy::viteConfig(), NodeWebGatewayProxy::viteConfigPath($hostAppPath));
                    }
                    $deployment->update(['env_values' => $envVars]);
                }
            }

            $runtime = $this->resolveApplicationRuntime($ssh, $template, $hostAppPath, $nodeTopology);

            $composeYaml = $this->renderCompose(
                $template,
                $deployment->container_name,
                (int) $deployment->assigned_port,
                $envVars,
                $databaseTemplate,
                $deployment,
                $deployment->selected_version,
                $hostAppPath,
                $runtime,
                $documentRoot,
                serveNextFrontend: $serveNextFrontend,
                nextFrontendRelativeDir: $nextFrontendRelativeDir,
                nodeTopology: $nodeTopology,
            );

            $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            $deployment->update(['docker_compose_content' => $composeYaml]);
            $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

            app(ContainerEnvironmentService::class)->syncDotEnvFile($ssh, $service, $deployment, $envVars);
            if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                if (($template->slug ?? '') === 'nodejs') {
                    $service->refresh();
                    $oldChecksum = data_get($service->service_meta, 'node_release.frontend_env_checksum');
                    $newChecksum = app(ContainerNodeBuildService::class)->frontendBuildEnvironmentChecksum($deployment->fresh());
                    if (! is_string($oldChecksum) || ! hash_equals($oldChecksum, $newChecksum)) {
                        app(ContainerNodeBuildService::class)->build(
                            $service,
                            $deployment->fresh(),
                            $ssh,
                            forceRebuild: true,
                            operationAlreadyLocked: true,
                            workloads: ['frontend'],
                        );
                    }
                } else {
                    $this->stackCommands->buildSplitWebFrontend(
                        $deployment->fresh(),
                        $ssh,
                        (string) data_get($nodeTopology, 'frontend.root'),
                        forceRebuild: true,
                    );
                }
            }

            if ($this->runtimeImages->usesRuntimeImage($template)) {
                $this->runtimeImages->ensureImage($ssh, $template, $deployment->selected_version, $service, $deployment);
            }

            if ($serveNextFrontend) {
                $this->ensureNextSidecarImages($ssh);
            }
            if (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                $this->ensureNodeWebSidecarImages($ssh, (string) ($nodeTopology['frontend_type'] ?? 'nextjs'));
            }

            $cutoverStarted = true;
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down --remove-orphans", self::DEPLOY_TIMEOUT);
            $this->composeUp($ssh, $containerPath, $this->runtimeImages->usesRuntimeImage($template), useExplicitComposeFile: true);
            if ($serveNextFrontend) {
                $this->waitForLaravelNextSidecarHealth($ssh, $deployment->container_name, 180);
            } elseif (($nodeTopology['topology'] ?? null) === 'split_web_api') {
                $this->waitForNodeSplitStackReadiness($ssh, $deployment, 180);
            }
            $this->syncPhpExtensionsIfSupported($ssh, $service, $deployment);
            $this->syncDatabaseCredentialsAfterStart($ssh, $service, $deployment, $containerPath);

            try {
                app(NginxProxyService::class)->refreshBoundDomainVhosts($service);
            } catch (\Throwable $e) {
                Log::warning('Could not refresh nginx vhost after environment apply', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $deployment->update(['status' => 'running']);
        } catch (\Throwable $e) {
            if (($template->slug ?? '') === 'nodejs' && $previousCompose !== '') {
                $this->rollbackNodeRedeploy(
                    $service,
                    $deployment,
                    $deployment->node,
                    $deployment->container_name,
                    [
                        'compose' => $previousCompose,
                        'deployment_status' => 'running',
                        'service_status' => $service->status,
                        'node_release' => $previousRelease,
                    ],
                    $cutoverStarted,
                );
                $service->refresh();
                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                if (is_array($previousRelease)) {
                    $meta['node_release'] = $previousRelease;
                } else {
                    unset($meta['node_release']);
                }
                $service->update(['service_meta' => $meta]);
            }
            throw $e;
        } finally {
            $ssh->disconnect();
            $operationLock?->release();
        }
    }

    public function persistProvisionTemplateSlug(Service $service, string $slug): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['provision_template_slug'] = $slug;
        $service->update(['service_meta' => $meta]);
        $service->unsetRelation('product');
    }

    public function deploymentNeedsPhpHeal(ContainerDeployment $deployment, string $slug): bool
    {
        if (in_array($slug, ['laravel', 'php'], true)) {
            return true;
        }

        $yaml = (string) ($deployment->docker_compose_content ?? '');

        return str_contains($yaml, 'talksasa-php-server')
            || str_contains($yaml, 'talksasa/php-runtime')
            || str_contains($yaml, 'php-fpm');
    }

    public function phpDocumentRootOnHost(SSHService $ssh, string $hostAppPath): string
    {
        try {
            $root = trim((string) $ssh->exec(
                app(LaravelProjectPathResolver::class)->phpDocumentRootCommand($hostAppPath),
                15
            ));
            if (preg_match('#^/app(?:/[A-Za-z0-9._-]+)?$#', $root) === 1) {
                return $root;
            }
        } catch (\Throwable) {
        }

        return '/app';
    }

    /**
     * DirectAdmin static_or_php converts often land on nginx:alpine while the
     * tree is a PHP app. Keep the billed product and host files; switch runtime only.
     */
    public function switchStaticSiteToPhpRuntime(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh
    ): string {
        $phpTemplate = ContainerTemplate::ensurePhpRuntime();
        $this->persistProvisionTemplateSlug($service, 'php');
        $service->refresh();
        $template = $this->resolveContainerTemplate($service);
        if (($template->slug ?? '') !== 'php') {
            $template = $phpTemplate;
        }

        $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name)
            ?? (self::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app');
        $documentRoot = $this->phpDocumentRootOnHost($ssh, $hostAppPath);
        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        $phpVersion = is_string($deployment->selected_version)
            && preg_match('/^\d+\.\d+/', $deployment->selected_version) === 1
            ? $deployment->selected_version
            : null;

        $composeYaml = $this->renderCompose(
            $template,
            $deployment->container_name,
            (int) $deployment->assigned_port,
            $envVars,
            null,
            $deployment,
            $phpVersion,
            $hostAppPath,
            null,
            $documentRoot,
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deployment->update([
            'docker_compose_content' => $composeYaml,
            'selected_version' => $phpVersion,
        ]);
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

        $this->runtimeImages->ensureImage($ssh, $template, $phpVersion, $service, $deployment);
        $this->restartAppService($ssh, $deployment->fresh());
        $this->waitForContainerRunning($ssh, $deployment->container_name);

        try {
            app(NginxProxyService::class)->refreshBoundDomainVhosts($service);
        } catch (\Throwable $e) {
            Log::warning('Could not refresh nginx vhost after static-to-php switch', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        return 'Switched this site from static nginx to PHP-FPM (document root '.$documentRoot
            .'). Files were kept; no database sidecar was added. Reload the site.';
    }

    public function refreshLaravelServeCompose(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        return $this->refreshPhpProductionRuntime($service, $deployment, $ssh);
    }

    public function refreshPhpProductionRuntime(Service $service, ContainerDeployment $deployment, SSHService $ssh): string
    {
        $service->loadMissing('product.containerTemplate');
        $template = $this->resolveContainerTemplate($service);
        $slug = $template->slug ?? null;

        if (! in_array($slug, ['laravel', 'php'], true)) {
            return '';
        }

        $hostAppPath = $this->resolveHostAppPath($template, $deployment->container_name);
        if (! $hostAppPath) {
            return '';
        }

        $documentRoot = $slug === 'php' ? '/app' : '/app/public';
        $serveNextFrontend = false;
        $nextFrontendRelativeDir = 'frontend';

        if ($slug === 'php') {
            $documentRoot = $this->phpDocumentRootOnHost($ssh, $hostAppPath);
        }

        if ($slug === 'laravel') {
            $resolver = app(LaravelProjectPathResolver::class);
            if ($resolver->hasProject($ssh, $hostAppPath)) {
                $resolved = $resolver->persistResolvedPaths($service, $ssh, $deployment);
                $documentRoot = $resolved['document_root'] ?? $resolver->resolveDocumentRoot($ssh, $hostAppPath) ?? $documentRoot;
            }

            $serveNextFrontend = $this->usesLaravelNextSidecarStack($deployment);
            if ($serveNextFrontend) {
                $nextFrontendRelativeDir = $this->stackCommands->resolveLaravelFrontendRelativeDir($ssh, $hostAppPath) ?? 'frontend';
                $ssh->upload(
                    LaravelNextGatewayProxy::scriptContents(
                        LaravelNextGatewayProxy::EDGE_INTERNAL_PORT,
                        LaravelNextGatewayProxy::BACKEND_PORT,
                        LaravelNextGatewayProxy::FRONTEND_PORT,
                    ),
                    LaravelNextGatewayProxy::hostScriptPath($hostAppPath)
                );
            }
        }

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
            $documentRoot,
            serveNextFrontend: $serveNextFrontend,
            nextFrontendRelativeDir: $nextFrontendRelativeDir,
        );

        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $deployment->update(['docker_compose_content' => $composeYaml]);
        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

        if ($this->runtimeImages->usesRuntimeImage($template)) {
            $this->runtimeImages->ensureImage($ssh, $template, $deployment->selected_version, $service, $deployment);
        }

        $this->composeUp($ssh, $containerPath, $this->runtimeImages->usesRuntimeImage($template));
        $this->waitForContainerRunning($ssh, $deployment->container_name);

        return 'Switched to nginx + PHP-FPM (document root '.$documentRoot.').';
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
        $inspect = app(ContainerRuntimeInspector::class)->inspect($ssh, $containerName);

        if (($inspect['missing'] ?? false) === true) {
            \Log::debug('Container not found in docker inspect', ['container_name' => $containerName]);

            return [
                'state' => 'unknown',
                'running' => false,
                'oom_killed' => false,
                'exit_code' => null,
                'internal_ip' => null,
            ];
        }

        $safeName = escapeshellarg($containerName);
        $ports = trim($ssh->exec(
            "docker inspect --type container --format '{{json .NetworkSettings.Ports}}' {$safeName} 2>/dev/null || echo ''",
            10
        ));

        \Log::debug('Container status check', [
            'container_name' => $containerName,
            'state' => $inspect['state'],
            'running' => $inspect['running'],
            'oom_killed' => $inspect['oom_killed'],
            'ports' => $ports,
        ]);

        return [
            'state' => $inspect['state'],
            'running' => $inspect['running'],
            'oom_killed' => $inspect['oom_killed'],
            'exit_code' => $inspect['exit_code'],
            'internal_ip' => $ports !== '' ? $ports : null,
            'full_data' => [
                'state' => $inspect['state'],
                'running' => $inspect['running'],
                'oom_killed' => $inspect['oom_killed'],
                'exit_code' => $inspect['exit_code'],
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
        } elseif (($service->effectiveContainerTemplate()?->slug ?? '') === 'wordpress') {
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
    private function databaseEnvironmentVariables(DatabaseTemplate $databaseTemplate, array $env, Service $service, ?string $appContainerName = null): array
    {
        return match ($databaseTemplate->type) {
            'mysql', 'mariadb' => $this->mysqlEnvironmentVariables($env, $service, $appContainerName),
            'postgresql' => $this->postgresqlEnvironmentVariables($env, $service, $appContainerName),
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
    private function mysqlEnvironmentVariables(array $env, Service $service, ?string $appContainerName = null): array
    {
        $dbName = $this->resolveDatabaseName($env, $service, ['MYSQL_DATABASE', 'DB_DATABASE']);
        $dbUser = $this->resolveDatabaseUsername($env, $service, ['MYSQL_USER', 'DB_USERNAME']);
        $dbPassword = (string) ($env['DB_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? Str::random(32));
        $rootPassword = (string) ($env['MYSQL_ROOT_PASSWORD'] ?? $dbPassword);
        $host = $appContainerName ? $this->sidecarDnsHost($appContainerName) : 'db';

        return [
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
            'MYSQL_DATABASE' => $dbName,
            'MYSQL_USER' => $dbUser,
            'MYSQL_PASSWORD' => $dbPassword,
            'DB_HOST' => $host,
            'DB_PORT' => '3306',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_CONNECTION' => 'mysql',
            'TALKSASA_DB_DNS' => $host,
            'DATABASE_URL' => sprintf(
                'mysql://%s:%s@%s:3306/%s',
                rawurlencode($dbUser),
                rawurlencode($dbPassword),
                $host,
                rawurlencode($dbName)
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function postgresqlEnvironmentVariables(array $env, Service $service, ?string $appContainerName = null): array
    {
        $dbName = $this->resolveDatabaseName($env, $service, ['POSTGRES_DB', 'DB_DATABASE']);
        $dbUser = $this->resolveDatabaseUsername($env, $service, ['POSTGRES_USER', 'DB_USERNAME']);
        $dbPassword = (string) ($env['POSTGRES_PASSWORD'] ?? $env['DB_PASSWORD'] ?? Str::random(32));
        $host = $appContainerName ? $this->sidecarDnsHost($appContainerName) : 'db';

        return [
            'POSTGRES_PASSWORD' => $dbPassword,
            'POSTGRES_DB' => $dbName,
            'POSTGRES_USER' => $dbUser,
            'DB_HOST' => $host,
            'DB_PORT' => '5432',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_CONNECTION' => 'pgsql',
            'TALKSASA_DB_DNS' => $host,
            'DATABASE_URL' => sprintf(
                'postgresql://%s:%s@%s:5432/%s',
                rawurlencode($dbUser),
                rawurlencode($dbPassword),
                $host,
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
     * Upgrade existing compose files to the current elastic resource policy. WordPress
     * additionally receives its DB/restart/upload hardening when older YAML is detected.
     */
    public function refreshComposeYamlIfStale(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment
    ): void {
        $service->loadMissing('product.containerTemplate');
        $template = $this->resolveContainerTemplate($service);

        if (! $template) {
            return;
        }

        $existing = (string) ($deployment->docker_compose_content ?? '');
        $containerName = $deployment->container_name;
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;

        if ($existing !== '' && ! $this->elasticResources->isCurrent($existing)) {
            try {
                $included = $service->product?->getIncludedContainerLimits($template, $deployment) ?? [
                    'cpu' => (float) ($deployment->cpu_limit ?: $template->required_cpu_cores ?: 1),
                    'memory_mb' => (int) ($deployment->memory_limit_mb ?: $template->required_ram_mb ?: 256),
                ];
                $appService = $this->usesLaravelNextSidecarStack($deployment)
                    ? LaravelNextGatewayProxy::BACKEND_SERVICE
                    : $containerName;
                $existing = $this->elasticResources->applyToYaml(
                    $existing,
                    $appService,
                    (float) $included['cpu'],
                    (int) $included['memory_mb']
                );
                $deployment->update(['docker_compose_content' => $existing]);
                $ssh->upload($existing, $containerPath.'/docker-compose.yml');
            } catch (\Throwable $e) {
                Log::warning('Elastic compose policy refresh failed', [
                    'deployment_id' => $deployment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (($template->slug ?? '') !== 'wordpress') {
            return;
        }

        $needsRefresh = $existing === ''
            || str_contains($existing, 'innodb-buffer-pool-size=512M')
            || ! preg_match('/^\s*restart:\s*[\'"]?always[\'"]?\s*$/mi', $existing)
            || str_contains($existing, 'service_healthy')
            || ! str_contains($existing, 'service_started')
            || ! str_contains($existing, 'innodb-buffer-pool-size=256M')
            || ! str_contains($existing, '127.0.0.1')
            || str_contains($existing, "-h', 'localhost'")
            || str_contains($existing, '-h localhost')
            || ! str_contains($existing, 'start_period: 300s')
            || ! str_contains($existing, 'uploads.ini')
            || ! str_contains($existing, 'talksasa-mysql')
            || ! str_contains($existing, 'innodb-use-native-aio=0');

        if (! $needsRefresh) {
            return;
        }

        $port = (int) ($deployment->assigned_port ?? 0);
        if ($port <= 0) {
            return;
        }

        $envVars = is_array($deployment->env_values) ? $deployment->env_values : [];
        if (trim((string) ($envVars['MYSQL_ROOT_PASSWORD'] ?? '')) === ''
            || trim((string) ($envVars['WORDPRESS_DB_PASSWORD'] ?? $envVars['MYSQL_PASSWORD'] ?? '')) === '') {
            \Log::warning('Skipping WordPress compose refresh: missing DB passwords', [
                'deployment_id' => $deployment->id,
            ]);

            return;
        }

        $hostAppPath = $this->resolveHostAppPath($template, $containerName);
        $databaseTemplate = $this->resolveDatabaseTemplateForService($service);

        try {
            $this->wordpressHardening->ensureUploadsIniFile($ssh, $containerName);
            $composeYaml = $this->renderCompose(
                $template,
                $containerName,
                $port,
                $envVars,
                $databaseTemplate,
                $deployment,
                $deployment->selected_version,
                $hostAppPath,
                null,
                null
            );
        } catch (\Throwable $e) {
            \Log::warning('WordPress compose refresh failed', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $deployment->update([
            'docker_compose_content' => $composeYaml,
            'restart_policy' => 'always',
        ]);

        $ssh->upload($composeYaml, $containerPath.'/docker-compose.yml');

        \Log::info('Refreshed WordPress docker-compose.yml with MySQL memory/restart hardening', [
            'deployment_id' => $deployment->id,
            'container_name' => $containerName,
        ]);
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
        app(ContainerBackupService::class)->purgeAllForService($service);
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
                $isApiEndpoint = $domain->isApiEndpoint();
                $hostname = (string) $domain->domain;
                if ($domain->deployment?->node) {
                    $nginxService->unbind($domain);
                } else {
                    $domain->delete();
                }
                if ($isApiEndpoint) {
                    app(ContainerDomainBindingService::class)
                        ->clearApiHostnameMetadata($service, $hostname);
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

    public function rebindDeploymentDomains(Service $service, ContainerDeployment $latestDeployment): void
    {
        $this->reattachAndRebindDomains($service, $latestDeployment);
    }

    public function rebindDeploymentDomainsStrict(Service $service, ContainerDeployment $latestDeployment): void
    {
        $this->reattachAndRebindDomains($service, $latestDeployment, true);
    }

    private function reattachAndBindPrimaryDomains(Service $service, ContainerDeployment $latestDeployment): void
    {
        $this->reattachAndRebindDomains($service, $latestDeployment);

        try {
            app(ContainerDomainBindingService::class)->attachPrimaryHosts(
                $service->fresh(['product', 'user', 'containerDeployment.node', 'containerDeployment.domains'])
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to auto-bind primary domain and www after deploy', [
                'service_id' => $service->id,
                'deployment_id' => $latestDeployment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reattachAndRebindDomains(
        Service $service,
        ContainerDeployment $latestDeployment,
        bool $throwOnFailure = false,
    ): void {
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
                        if ($throwOnFailure) {
                            throw $domainError;
                        }
                        \Log::warning('Failed to rebind container domain after redeploy', [
                            'service_id' => $service->id,
                            'domain' => $domain->domain,
                            'error' => $domainError->getMessage(),
                        ]);
                    }
                }
            }

            app(ContainerDomainBindingService::class)->syncManagedARecords($service->fresh([
                'containerDeployment.node',
                'containerDeployment.domains',
            ]));
        } catch (\Throwable $e) {
            if ($throwOnFailure) {
                throw $e;
            }
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

        $template = $this->resolveContainerTemplate($service) ?? $service->product?->containerTemplate;

        $included = $service->product?->getIncludedContainerLimits(
            $template,
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

        $cpuShare = (float) ($meta['resource_share']['cpu'] ?? 0);
        $memShare = (float) ($meta['resource_share']['memory'] ?? 0);
        if ($cpuShare > 0 && $cpuShare < 1 && isset($payload['cpu_limit'])) {
            $payload['cpu_limit'] = max(0.05, round($payload['cpu_limit'] * $cpuShare, 2));
        }
        if ($memShare > 0 && $memShare < 1 && isset($payload['memory_limit_mb'])) {
            $payload['memory_limit_mb'] = max(64, (int) round($payload['memory_limit_mb'] * $memShare));
        }

        return $payload;
    }

    /**
     * Effective stack template for a service: provision/meta override, then product FK.
     * Shared Application Hosting plans store the stack on service_meta, not the product.
     */
    public function resolveContainerTemplate(Service $service): ?ContainerTemplate
    {
        return $service->effectiveContainerTemplate();
    }

    /**
     * @return array<string, string>
     */
    private function projectRoleLinkEnv(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (($meta['project_role'] ?? null) !== 'frontend') {
            return [];
        }

        $backendId = (int) ($meta['backend_service_id'] ?? 0);
        if ($backendId <= 0) {
            return [];
        }

        $backend = Service::query()->with('containerDeployment')->find($backendId);
        $deployment = $backend?->containerDeployment;
        if (! $deployment) {
            return [];
        }

        $host = $deployment->hostname
            ?: $deployment->container_name
            ?: null;

        if (! is_string($host) || $host === '') {
            return [];
        }

        $port = $deployment->host_port ?: 8000;
        $url = str_starts_with($host, 'http') ? $host : "http://{$host}:{$port}";

        return [
            'BACKEND_URL' => $url,
            'NEXT_PUBLIC_API_URL' => $url,
            'API_URL' => $url,
        ];
    }
}
