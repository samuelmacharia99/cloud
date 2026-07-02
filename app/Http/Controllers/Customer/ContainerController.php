<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ImportContainerDatabaseRequest;
use App\Http\Requests\Customer\PullContainerGitRepositoryRequest;
use App\Http\Requests\Customer\UpdateContainerGitRepositoryRequest;
use App\Jobs\InitializeContainerAppJob;
use App\Jobs\PullContainerGitRepositoryJob;
use App\Models\ContainerBackup;
use App\Models\ContainerGitPull;
use App\Models\ContainerDomain;
use App\Models\ContainerFileAuditLog;
use App\Models\ContainerMetric;
use App\Models\DatabaseTemplate;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Customer\CustomerServiceCancellationService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Provisioning\ContainerBackupService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDeployOptions;
use App\Services\Provisioning\ContainerFileService;
use App\Services\Provisioning\ContainerGitCredentialsService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\Provisioning\LaravelAppInitializationService;
use App\Services\Provisioning\NginxProxyService;
use App\Services\SSH\SSHService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContainerController extends Controller
{
    /**
     * Show container dashboard
     */
    public function show(Service $service): View|RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($invoice = $service->unpaidActivationInvoice()) {
            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('info', 'Complete payment to activate this service.');
        }

        if ($service->product?->type !== 'container_hosting') {
            abort(404);
        }

        $service->load('containerDeployment', 'product.containerTemplate');

        $deployment = $service->containerDeployment;
        $status = null;

        if ($deployment) {
            $containerService = new ContainerDeploymentService;
            try {
                $status = $containerService->getStatus($service);
                $this->reconcileStuckProvisioningState($service, $deployment, $status);
                $service->refresh();
                $deployment = $service->containerDeployment;
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch status for service {$service->id}");
            }
        }

        $databaseContext = $this->buildDatabaseContext($service, $deployment);
        $databaseConsoleEnabled = $this->isDatabaseConsoleEnabled();
        $isLaravelTemplate = ($service->product?->containerTemplate?->slug ?? '') === 'laravel';
        $gitRepositoryService = app(ContainerGitRepositoryService::class);
        $gitCredentialsService = app(ContainerGitCredentialsService::class);
        $supportsGitRepository = $gitRepositoryService->supportsTemplate($service->product?->containerTemplate?->slug);
        $gitRepository = $supportsGitRepository ? array_merge(
            $gitRepositoryService->repositorySettings($service),
            [
                'has_repo_token' => $gitCredentialsService->hasRepositoryToken($service),
                'has_composer_auth' => $gitCredentialsService->hasComposerAuth($service),
            ]
        ) : null;
        $containerLimits = $service->product->getIncludedContainerLimits(
            $service->product->containerTemplate,
            $deployment
        );
        $dbImportMaxMb = (int) config('security.container_db_import.max_size_mb', 50);

        $latestBackup = null;
        $domainCount = 0;
        $domainsMissingSsl = 0;

        if ($deployment) {
            $deployment->loadMissing('domains');

            $latestBackup = $deployment->backups()
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->first();

            $domainCount = $deployment->domains->count();
            $domainsMissingSsl = $deployment->domains
                ->filter(fn ($domain) => $domain->status === 'active' && ! $domain->ssl_enabled)
                ->count();
        }

        return view('customer.services.container', compact(
            'service',
            'deployment',
            'status',
            'databaseContext',
            'databaseConsoleEnabled',
            'isLaravelTemplate',
            'supportsGitRepository',
            'gitRepository',
            'containerLimits',
            'dbImportMaxMb',
            'latestBackup',
            'domainCount',
            'domainsMissingSsl',
        ));
    }

    /**
     * Restart container
     */
    public function restart(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService;
            $containerService->restart($service);

            return back()->with('success', 'Container restarted successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to restart container: '.$e->getMessage()]);
        }
    }

    /**
     * Stop container
     */
    public function stop(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService;
            $containerService->suspend($service);

            return back()->with('success', 'Container stopped successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to stop container for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to stop container: '.$e->getMessage()]);
        }
    }

    /**
     * Start container
     */
    public function start(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService;
            $containerService->unsuspend($service);

            return back()->with('success', 'Container started successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to start container for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to start container: '.$e->getMessage()]);
        }
    }

    /**
     * Redeploy container stack
     */
    public function redeploy(Service $service, Request $request): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            if ($deployment->status === 'deploying' || $service->status === 'provisioning') {
                $isStale = $deployment->updated_at && $deployment->updated_at->lt(now()->subMinutes(5));
                if ($isStale) {
                    $deployment->update([
                        'status' => 'failed',
                        'last_status_check_at' => now(),
                        'last_status_check_output' => 'Provisioning marked failed automatically after stale timeout.',
                    ]);
                    if ($service->status === 'provisioning') {
                        $service->update(['status' => 'failed']);
                    }
                    $service->refresh();
                    $deployment = $service->containerDeployment;
                } else {
                    return back()->withErrors(['error' => 'Deployment already in progress. Please wait and try again.']);
                }
            }

            if (! $deployment->node || ! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $resetDatabase = $request->boolean('reset_database');
            $containerService = new ContainerDeploymentService;
            $result = $containerService->deploy(
                $service,
                ContainerDeployOptions::redeploy($resetDatabase)
            );

            $message = 'Container stack redeployed successfully.';
            if ($result->databaseReset) {
                $message .= ' Database volume was reset to a fresh empty database.';
            }
            if ($result->laravelDatabaseSyncMessage) {
                $message .= ' '.$result->laravelDatabaseSyncMessage;
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Failed to redeploy container for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to redeploy container: '.$e->getMessage()]);
        }
    }

    public function initializeLaravel(Service $service, LaravelAppInitializationService $initializationService): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
                return back()->withErrors(['error' => 'Application initialization is only available for Laravel containers.']);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment || $deployment->status !== 'running') {
                return back()->withErrors(['error' => 'Start the container before initializing the Laravel application.']);
            }

            $initialization = $initializationService->requestInitialization($service, auth()->user());
            InitializeContainerAppJob::dispatch($initialization->id)->afterResponse();

            return back()->with('success', 'Laravel initialization started. Progress updates appear below.');
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Log::error("Failed to start Laravel initialization for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to start initialization: '.$e->getMessage()]);
        }
    }

    public function clearAppDirectory(Service $service, LaravelAppInitializationService $initializationService): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
                return back()->withErrors(['error' => 'Clearing /app is only available for Laravel containers.']);
            }

            $result = $initializationService->clearApplicationDirectory($service);

            return back()->with('success', $result['message']);
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Log::error("Failed to clear /app for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to clear /app: '.$e->getMessage()]);
        }
    }

    public function laravelSetupStatus(Service $service, LaravelAppInitializationService $initializationService): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if (($service->product?->containerTemplate?->slug ?? '') !== 'laravel') {
            return response()->json(['error' => 'Not a Laravel container service'], 400);
        }

        $latest = $initializationService->latestInitialization($service);

        return response()->json([
            'checklist' => $initializationService->getSetupChecklist($service),
            'app_directory' => $initializationService->getAppDirectoryStatus($service),
            'initialization' => $latest ? [
                'id' => $latest->id,
                'status' => $latest->status,
                'steps' => $latest->steps,
                'log' => $latest->log,
                'error_message' => $latest->error_message,
                'started_at' => $latest->started_at?->toIso8601String(),
                'completed_at' => $latest->completed_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function updateGitRepository(
        Service $service,
        UpdateContainerGitRepositoryRequest $request,
        ContainerGitRepositoryService $gitRepositoryService
    ): RedirectResponse {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! $gitRepositoryService->supportsTemplate($service->product?->containerTemplate?->slug)) {
            return back()->withErrors(['error' => 'Git repository connections are not supported for this container type.']);
        }

        try {
            $gitRepositoryService->connect(
                $service,
                $request->input('source_repo_url'),
                (string) $request->input('source_repo_branch', 'main'),
                $request->input('source_repo_token'),
                $request->input('composer_github_token'),
                $request->boolean('remove_repo_token'),
                $request->boolean('remove_composer_auth'),
            );

            return redirect()
                ->route('customer.services.container.show', ['service' => $service, 'tab' => 'github'])
                ->with('success', 'Git repository saved. Use Pull latest to sync code into /app.');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Log::error("Failed to connect Git repository for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Failed to save repository settings: '.$e->getMessage()]);
        }
    }

    public function pullGitRepository(
        Service $service,
        PullContainerGitRepositoryRequest $request,
        ContainerGitRepositoryService $gitRepositoryService
    ): RedirectResponse|\Illuminate\Http\JsonResponse {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! $gitRepositoryService->supportsTemplate($service->product?->containerTemplate?->slug)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Git repository pulls are not supported for this container type.'], 400);
            }

            return back()->withErrors(['error' => 'Git repository pulls are not supported for this container type.']);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Start the container before pulling code from Git.'], 400);
            }

            return back()->withErrors(['error' => 'Start the container before pulling code from Git.']);
        }

        try {
            $pull = $gitRepositoryService->requestPull(
                $service,
                auth()->user(),
                $request->boolean('replace_existing'),
                $request->boolean('run_composer', true),
                $request->boolean('run_migrations', true),
            );

            PullContainerGitRepositoryJob::dispatch($pull->id)->afterResponse();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Git pull started. Progress updates appear below.',
                    'pull' => $this->formatGitPull($pull),
                ]);
            }

            return redirect()
                ->route('customer.services.container.show', ['service' => $service, 'tab' => 'github'])
                ->with('success', 'Git pull started. Progress updates appear below.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Log::error("Failed to pull Git repository for service {$service->id}: ".$e->getMessage());

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Failed to start Git pull: '.$e->getMessage()], 500);
            }

            return back()->withErrors(['error' => 'Failed to start Git pull: '.$e->getMessage()]);
        }
    }

    public function gitPullStatus(Service $service, ContainerGitRepositoryService $gitRepositoryService): \Illuminate\Http\JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! $gitRepositoryService->supportsTemplate($service->product?->containerTemplate?->slug)) {
            return response()->json(['error' => 'Git repository pulls are not supported for this container type.'], 400);
        }

        $latest = $gitRepositoryService->latestPull($service);
        $settings = $gitRepositoryService->repositorySettings($service);

        return response()->json([
            'repository' => $settings,
            'pull' => $latest ? $this->formatGitPull($latest) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatGitPull(ContainerGitPull $pull): array
    {
        return [
            'id' => $pull->id,
            'status' => $pull->status,
            'steps' => $pull->steps,
            'log' => $pull->log,
            'error_message' => $pull->error_message,
            'commit' => $pull->commit,
            'started_at' => $pull->started_at?->toIso8601String(),
            'completed_at' => $pull->completed_at?->toIso8601String(),
        ];
    }

    private function reconcileStuckProvisioningState(Service $service, $deployment, ?array $status): void
    {
        if (! $deployment || $deployment->status !== 'deploying') {
            return;
        }

        // If container is actually running, heal stale DB status.
        if (($status['running'] ?? false) === true) {
            $deployment->update([
                'status' => 'running',
                'last_status_check_at' => now(),
            ]);
            if ($service->status === 'provisioning' || $service->status === 'failed') {
                $service->update(['status' => 'active']);
            }

            return;
        }

        // If provisioning has been stuck for too long, fail it so user can recover.
        if ($deployment->updated_at && $deployment->updated_at->lt(now()->subMinutes(5))) {
            $deployment->update([
                'status' => 'failed',
                'last_status_check_at' => now(),
                'last_status_check_output' => 'Provisioning timed out and was marked failed.',
            ]);
            if ($service->status === 'provisioning') {
                $service->update(['status' => 'failed']);
            }
        }
    }

    /**
     * Execute a read-only database query against sidecar DB.
     */
    public function databaseQuery(Service $service, Request $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $validated = $request->validate([
            'query' => 'required|string|max:2000',
            'format' => 'nullable|in:text,csv',
        ]);

        if (! $this->isDatabaseConsoleEnabled()) {
            return response()->json(['error' => 'Database console is disabled by administrator'], 403);
        }

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Invalid service type'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->isRunning()) {
            return response()->json(['error' => 'Container must be running to query database'], 400);
        }

        if (! $deployment->node || ! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
            return response()->json(['error' => 'Container host is not properly configured'], 400);
        }

        $databaseContext = $this->buildDatabaseContext($service, $deployment);
        if (! $databaseContext['available']) {
            return response()->json(['error' => 'No database sidecar configured for this service'], 400);
        }

        $query = trim($validated['query']);
        $format = $validated['format'] ?? 'text';

        // Tight security: read-only statements only.
        if (! preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query)) {
            return response()->json(['error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN)'], 422);
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE|REPLACE|GRANT|REVOKE)\b/i', $query)) {
            return response()->json(['error' => 'Write/DDL statements are not permitted'], 422);
        }
        if (str_contains($query, ';')) {
            return response()->json(['error' => 'Multiple statements are not allowed'], 422);
        }
        if (preg_match('/^SELECT\b/i', $query) && ! preg_match('/\bLIMIT\s+\d+\b/i', $query)) {
            $query .= ' LIMIT 200';
        }

        try {
            $ssh = SSHService::forNode($deployment->node);
            $result = $this->runReadOnlyDatabaseQuery($ssh, $deployment, $databaseContext, $query, $format);
            $this->logDatabaseQuery($service, $query, $format, true);

            return response()->json([
                'success' => true,
                'format' => $format,
                'output' => $result['output'],
                'csv' => $result['csv'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::warning("Database query failed for service {$service->id}: ".$e->getMessage());
            $this->logDatabaseQuery($service, $query, $format, false);

            return response()->json(['error' => 'Query failed: '.$e->getMessage()], 500);
        }
    }

    public function databaseImport(Service $service, ImportContainerDatabaseRequest $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! $this->isDatabaseConsoleEnabled()) {
            return response()->json(['error' => 'Database console is disabled by administrator'], 403);
        }

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Invalid service type'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->isRunning()) {
            return response()->json(['error' => 'Container must be running to import a database'], 400);
        }

        if (! $deployment->node || ! $deployment->node->ssh_username || (! $deployment->node->ssh_password && ! $deployment->node->da_login_key)) {
            return response()->json(['error' => 'Container host is not properly configured'], 400);
        }

        $databaseContext = $this->buildDatabaseContext($service, $deployment);
        if (! $databaseContext['available']) {
            return response()->json(['error' => 'No database sidecar configured for this service'], 400);
        }

        if (! in_array($databaseContext['type'], ['mysql', 'mariadb', 'postgresql'], true)) {
            return response()->json(['error' => 'SQL import is only supported for MySQL, MariaDB, and PostgreSQL'], 400);
        }

        $file = $request->file('file');
        $sql = file_get_contents($file->getRealPath());
        if ($sql === false || trim($sql) === '') {
            return response()->json(['error' => 'SQL file is empty or unreadable'], 422);
        }

        try {
            $this->assertSafeSqlImport($sql);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        try {
            $ssh = SSHService::forNode($deployment->node);
            $output = $this->importDatabaseSql($ssh, $deployment, $databaseContext, $sql);
            $this->logDatabaseImport($service, $file->getClientOriginalName(), (int) $file->getSize(), true);

            return response()->json([
                'success' => true,
                'message' => 'Database import completed successfully.',
                'output' => $output !== '' ? $output : 'Import finished with no output.',
            ]);
        } catch (\Exception $e) {
            \Log::warning("Database import failed for service {$service->id}: ".$e->getMessage());
            $this->logDatabaseImport($service, $file->getClientOriginalName(), (int) $file->getSize(), false);

            return response()->json(['error' => 'Import failed: '.$e->getMessage()], 500);
        }
    }

    public function databaseHistory(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! $this->isDatabaseConsoleEnabled()) {
            return response()->json(['error' => 'Database console is disabled by administrator'], 403);
        }

        $history = ContainerFileAuditLog::query()
            ->where('service_id', $service->id)
            ->where('user_id', auth()->id())
            ->whereIn('action', ['db_query', 'db_import'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ContainerFileAuditLog $row) => [
                'at' => $row->created_at?->toDateTimeString(),
                'action' => $row->action,
                'query' => $row->metadata['query_preview'] ?? $row->metadata['filename'] ?? '',
                'format' => $row->metadata['format'] ?? 'text',
                'success' => (bool) ($row->metadata['success'] ?? false),
            ])
            ->values();

        return response()->json(['history' => $history]);
    }

    /**
     * Get container logs
     */
    public function logs(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $containerService = new ContainerDeploymentService;
            $logs = $containerService->getLogs($service, 100);

            return response()->json(['logs' => $logs]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch logs for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to fetch logs'], 500);
        }
    }

    /**
     * Get container metrics for chart display
     */
    public function metrics(Service $service, Request $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return response()->json([
                    'labels' => [],
                    'cpu' => [],
                    'memory' => [],
                    'net_rx' => [],
                    'net_tx' => [],
                    'disk_read' => [],
                    'disk_write' => [],
                    'summary' => null,
                ]);
            }

            // Parse hours parameter (1, 6, 24, 168 for 7 days)
            $hours = (int) $request->query('hours', 24);
            $validHours = [1, 6, 24, 168];
            if (! in_array($hours, $validHours)) {
                $hours = 24;
            }

            // Fetch metrics for the requested period
            $metrics = ContainerMetric::where('container_deployment_id', $deployment->id)
                ->where('recorded_at', '>=', now()->subHours($hours))
                ->orderBy('recorded_at')
                ->get();

            $labels = $metrics->map(fn ($m) => $m->recorded_at->format('H:i'))->toArray();
            $cpuData = $metrics->map(fn ($m) => round($m->cpu_percentage, 2))->toArray();
            $memoryData = $metrics->map(fn ($m) => $m->memory_used_mb)->toArray();
            $netRxData = $metrics->map(fn ($m) => $m->net_io_rx_bytes ?? 0)->toArray();
            $netTxData = $metrics->map(fn ($m) => $m->net_io_tx_bytes ?? 0)->toArray();
            $diskReadData = $metrics->map(fn ($m) => $m->block_io_read_bytes ?? 0)->toArray();
            $diskWriteData = $metrics->map(fn ($m) => $m->block_io_write_bytes ?? 0)->toArray();

            $includedLimits = $service->product->getIncludedContainerLimits(
                $service->product->containerTemplate,
                $deployment
            );

            // Calculate summary stats
            $summary = null;
            if ($metrics->count() > 0) {
                $summary = [
                    'cpu_avg' => round($metrics->avg('cpu_percentage'), 2),
                    'cpu_peak' => round($metrics->max('cpu_percentage'), 2),
                    'memory_avg' => round($metrics->avg('memory_used_mb'), 0),
                    'memory_peak' => (int) $metrics->max('memory_used_mb'),
                    'memory_limit_mb' => $metrics->first()?->memory_limit_mb ?: $includedLimits['memory_mb'],
                    'net_rx_total' => $metrics->sum('net_io_rx_bytes'),
                    'net_tx_total' => $metrics->sum('net_io_tx_bytes'),
                    'uptime_seconds' => $deployment->getUptimeSeconds(),
                    'uptime_human' => $this->formatUptime($deployment->getUptimeSeconds()),
                ];
            }

            return response()->json([
                'labels' => $labels,
                'cpu' => $cpuData,
                'memory' => $memoryData,
                'net_rx' => $netRxData,
                'net_tx' => $netTxData,
                'disk_read' => $diskReadData,
                'disk_write' => $diskWriteData,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch metrics for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to fetch metrics'], 500);
        }
    }

    /**
     * Get storage usage stats for the container
     */
    public function storageStats(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return response()->json(['error' => 'Container not deployed yet'], 400);
            }

            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $stats = $fileService->getStorageUsage($deployment);

            return response()->json([
                'used_bytes' => $stats['used_bytes'],
                'human' => $stats['human'],
                'container_name' => $deployment->container_name,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch storage stats for service {$service->id}: ".$e->getMessage());

            return response()->json(['error' => 'Failed to fetch storage stats'], 500);
        }
    }

    /**
     * Format uptime in human-readable format
     */
    private function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }

    private function buildDatabaseContext(Service $service, $deployment): array
    {
        $fallback = [
            'available' => false,
            'type' => null,
            'host' => 'db',
            'port' => null,
            'database' => null,
            'username' => null,
            'password_masked' => null,
        ];

        if (! $deployment || ! $deployment->env_values || ! is_array($deployment->env_values)) {
            return $fallback;
        }

        $env = $deployment->env_values;
        $databaseId = $service->service_meta['database_id'] ?? null;
        $template = $databaseId ? DatabaseTemplate::find($databaseId) : null;
        $type = $template?->type ?? $this->inferDatabaseTypeFromEnv($env);

        if (! $type || ! in_array($type, ['mysql', 'mariadb', 'postgresql'], true)) {
            return $fallback;
        }

        $password = match ($type) {
            'mysql', 'mariadb' => $env['DB_PASSWORD'] ?? ($env['MYSQL_PASSWORD'] ?? null),
            'postgresql' => $env['DB_PASSWORD'] ?? ($env['POSTGRES_PASSWORD'] ?? null),
            default => null,
        };

        return match ($type) {
            'mysql', 'mariadb' => [
                'available' => true,
                'type' => $type,
                'host' => $env['DB_HOST'] ?? 'db',
                'port' => $env['DB_PORT'] ?? '3306',
                'database' => $env['DB_DATABASE'] ?? ($env['MYSQL_DATABASE'] ?? 'appdb'),
                'username' => $env['DB_USERNAME'] ?? ($env['MYSQL_USER'] ?? 'appuser'),
                'password' => $password,
                'password_masked' => $this->maskSecret($password),
                'root_password_masked' => $this->maskSecret($env['MYSQL_ROOT_PASSWORD'] ?? null),
                'connection' => sprintf(
                    'mysql://%s:%s@%s:%s/%s',
                    $env['DB_USERNAME'] ?? ($env['MYSQL_USER'] ?? 'appuser'),
                    '********',
                    $env['DB_HOST'] ?? 'db',
                    $env['DB_PORT'] ?? '3306',
                    $env['DB_DATABASE'] ?? ($env['MYSQL_DATABASE'] ?? 'appdb')
                ),
            ],
            'postgresql' => [
                'available' => true,
                'type' => $type,
                'host' => $env['DB_HOST'] ?? 'db',
                'port' => $env['DB_PORT'] ?? '5432',
                'database' => $env['DB_DATABASE'] ?? ($env['POSTGRES_DB'] ?? 'appdb'),
                'username' => $env['DB_USERNAME'] ?? ($env['POSTGRES_USER'] ?? 'appuser'),
                'password' => $password,
                'password_masked' => $this->maskSecret($password),
                'connection' => $env['DATABASE_URL'] ?? sprintf(
                    'postgresql://%s@db:5432/%s',
                    $env['DB_USERNAME'] ?? ($env['POSTGRES_USER'] ?? 'appuser'),
                    $env['DB_DATABASE'] ?? ($env['POSTGRES_DB'] ?? 'appdb')
                ),
            ],
            default => $fallback,
        };
    }

    private function inferDatabaseTypeFromEnv(array $env): ?string
    {
        if (! empty($env['POSTGRES_DB']) || ($env['DB_CONNECTION'] ?? '') === 'pgsql') {
            return 'postgresql';
        }

        if (! empty($env['MYSQL_DATABASE']) || ! empty($env['DB_DATABASE'])) {
            return 'mysql';
        }

        return null;
    }

    private function maskSecret(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', max(0, $length - 4)).substr($value, -2);
    }

    private function runReadOnlyDatabaseQuery(SSHService $ssh, $deployment, array $databaseContext, string $query, string $format = 'text'): array
    {
        $containerPath = '/opt/talksasa/containers/'.$deployment->container_name;
        $dbType = $databaseContext['type'];
        $queryArg = escapeshellarg($query);

        if (in_array($dbType, ['mysql', 'mariadb'], true)) {
            $db = escapeshellarg((string) ($databaseContext['database'] ?? 'appdb'));
            $user = escapeshellarg((string) ($databaseContext['username'] ?? 'appuser'));
            $password = escapeshellarg((string) ($deployment->env_values['DB_PASSWORD'] ?? $deployment->env_values['MYSQL_PASSWORD'] ?? ''));

            $command = "cd {$containerPath} && docker compose exec -T -e MYSQL_PWD={$password} db ".
                "mysql --batch --raw -u {$user} {$db} -e {$queryArg}";

            $output = $ssh->exec($command, 20);
            if ($format === 'csv') {
                return [
                    'output' => $output,
                    'csv' => $this->tabSeparatedToCsv($output),
                ];
            }

            return ['output' => $output];
        }

        if ($dbType === 'postgresql') {
            $db = escapeshellarg((string) ($databaseContext['database'] ?? 'appdb'));
            $user = escapeshellarg((string) ($databaseContext['username'] ?? 'appuser'));
            $password = escapeshellarg((string) ($deployment->env_values['DB_PASSWORD'] ?? $deployment->env_values['POSTGRES_PASSWORD'] ?? ''));

            $csvMode = $format === 'csv' ? '--csv ' : '';
            $command = "cd {$containerPath} && docker compose exec -T -e PGPASSWORD={$password} db ".
                "psql {$csvMode}-U {$user} -d {$db} -c {$queryArg}";

            $output = $ssh->exec($command, 20);

            return [
                'output' => $output,
                'csv' => $format === 'csv' ? $output : null,
            ];
        }

        throw new \RuntimeException('Interactive query is not supported for this database type yet');
    }

    private function importDatabaseSql(SSHService $ssh, $deployment, array $databaseContext, string $sql): string
    {
        $containerPath = '/opt/talksasa/containers/'.$deployment->container_name;
        $importDir = $containerPath.'/.db-imports';
        $ssh->mkdirp($importDir);

        $remotePath = $importDir.'/import_'.time().'_'.bin2hex(random_bytes(4)).'.sql';
        $ssh->upload($sql, $remotePath);

        try {
            $remoteArg = escapeshellarg($remotePath);
            $dbType = $databaseContext['type'];

            if (in_array($dbType, ['mysql', 'mariadb'], true)) {
                $db = escapeshellarg((string) ($databaseContext['database'] ?? 'appdb'));
                $user = escapeshellarg((string) ($databaseContext['username'] ?? 'appuser'));
                $password = escapeshellarg((string) ($deployment->env_values['DB_PASSWORD'] ?? $deployment->env_values['MYSQL_PASSWORD'] ?? ''));

                $command = "cd {$containerPath} && cat {$remoteArg} | docker compose exec -T -e MYSQL_PWD={$password} db "
                    ."mysql --batch -u {$user} {$db}";

                return $ssh->exec($command, 180);
            }

            if ($dbType === 'postgresql') {
                $db = escapeshellarg((string) ($databaseContext['database'] ?? 'appdb'));
                $user = escapeshellarg((string) ($databaseContext['username'] ?? 'appuser'));
                $password = escapeshellarg((string) ($deployment->env_values['DB_PASSWORD'] ?? $deployment->env_values['POSTGRES_PASSWORD'] ?? ''));

                $command = "cd {$containerPath} && cat {$remoteArg} | docker compose exec -T -e PGPASSWORD={$password} db "
                    ."psql -v ON_ERROR_STOP=1 -U {$user} -d {$db}";

                return $ssh->exec($command, 180);
            }

            throw new \RuntimeException('SQL import is not supported for this database type');
        } finally {
            try {
                $ssh->exec('rm -f '.escapeshellarg($remotePath), 10);
            } catch (\Throwable) {
                // Best-effort cleanup of temporary import file on the node.
            }
        }
    }

    private function assertSafeSqlImport(string $sql): void
    {
        $blocked = [
            '/\bDROP\s+DATABASE\b/i',
            '/\bCREATE\s+DATABASE\b/i',
            '/\bDROP\s+SCHEMA\b/i',
            '/\bGRANT\s+/i',
            '/\bREVOKE\s+/i',
        ];

        foreach ($blocked as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new \InvalidArgumentException(
                    'SQL file contains disallowed statements (database-level privilege or schema drops). Remove them and try again.'
                );
            }
        }
    }

    private function tabSeparatedToCsv(string $input): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $input)), fn ($line) => $line !== ''));
        if (empty($lines)) {
            return '';
        }

        $stream = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            fputcsv($stream, explode("\t", $line));
        }
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    private function logDatabaseQuery(Service $service, string $query, string $format, bool $success): void
    {
        try {
            ContainerFileAuditLog::create([
                'service_id' => $service->id,
                'user_id' => auth()->id(),
                'deployment_id' => $service->containerDeployment?->id,
                'action' => 'db_query',
                'path' => '/db',
                'metadata' => [
                    'query_preview' => mb_substr(preg_replace('/\s+/', ' ', trim($query)) ?? '', 0, 200),
                    'format' => $format,
                    'success' => $success,
                ],
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to write db_query audit log', ['service_id' => $service->id, 'error' => $e->getMessage()]);
        }
    }

    private function logDatabaseImport(Service $service, string $filename, int $bytes, bool $success): void
    {
        try {
            ContainerFileAuditLog::create([
                'service_id' => $service->id,
                'user_id' => auth()->id(),
                'deployment_id' => $service->containerDeployment?->id,
                'action' => 'db_import',
                'path' => '/db',
                'metadata' => [
                    'filename' => $filename,
                    'bytes' => $bytes,
                    'success' => $success,
                ],
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to write db_import audit log', ['service_id' => $service->id, 'error' => $e->getMessage()]);
        }
    }

    private function isDatabaseConsoleEnabled(): bool
    {
        $value = strtolower((string) Setting::getValue('container_db_console_enabled', '1'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get comprehensive health and status data for the container
     */
    public function health(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Invalid service type'], 400);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return response()->json(['error' => 'Not deployed'], 400);
        }

        $deployment->load('node', 'domains', 'migratedFromNode');

        // Calculate health score
        $score = 100;
        if ($deployment->status !== 'running') {
            $score -= 50;
        }
        $score -= min(30, $deployment->restart_attempts * 5);
        if (! $deployment->last_status_check_at || $deployment->last_status_check_at->lt(now()->subHour())) {
            $score -= 5;
        }
        if ($deployment->last_restart_at && $deployment->last_restart_at->gt(now()->subHour())) {
            $score -= 5;
        }
        $score = max(0, $score);

        // Determine incident level
        $incidentLevel = 'none';
        $incidentMessage = null;
        if ($deployment->status === 'failed') {
            $incidentLevel = 'critical';
            $incidentMessage = 'Container has failed. Check logs for details.';
        } elseif ($deployment->restart_attempts > 5) {
            $incidentLevel = 'warning';
            $incidentMessage = "{$deployment->restart_attempts} restarts detected. Monitor for instability.";
        } elseif ($deployment->last_restart_at && $deployment->last_restart_at->gt(now()->subHour())) {
            $incidentLevel = 'warning';
            $incidentMessage = 'Container restarted recently (within the last hour).';
        }

        // Calculate bandwidth analytics
        $bwQuery = fn (int $hours) => ContainerMetric::where('container_deployment_id', $deployment->id)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->selectRaw('SUM(net_io_rx_bytes) as rx, SUM(net_io_tx_bytes) as tx, MAX(net_io_rx_bytes + net_io_tx_bytes) as peak')
            ->first();

        $bw1h = $bwQuery(1);
        $bw24h = $bwQuery(24);
        $bw7d = $bwQuery(168);

        // Calculate network activity rate (bytes/min from last 5 metrics)
        $recentMetrics = ContainerMetric::where('container_deployment_id', $deployment->id)
            ->orderByDesc('recorded_at')->limit(5)->get();
        $activityRate = 0;
        if ($recentMetrics->count() >= 2) {
            $first = $recentMetrics->first();
            $last = $recentMetrics->last();
            $bytesTotal = ($first->net_io_rx_bytes + $first->net_io_tx_bytes) -
                         ($last->net_io_rx_bytes + $last->net_io_tx_bytes);
            $minutesElapsed = max(1, $first->recorded_at->diffInMinutes($last->recorded_at) ?: 1);
            $activityRate = max(0, $bytesTotal / $minutesElapsed);
        }

        // Build deployment timeline
        $timeline = [];
        if ($deployment->deployed_at) {
            $timeline[] = [
                'type' => 'deployed',
                'label' => 'Deployed',
                'at' => $deployment->deployed_at->toIso8601String(),
                'human' => $deployment->deployed_at->diffForHumans(),
            ];
        }
        if ($deployment->migrated_at && $deployment->migrated_from_node_id) {
            $fromHost = $deployment->migratedFromNode?->hostname ?? 'unknown node';
            $timeline[] = [
                'type' => 'migrated',
                'label' => "Migrated from {$fromHost}",
                'at' => $deployment->migrated_at->toIso8601String(),
                'human' => $deployment->migrated_at->diffForHumans(),
            ];
        }
        if ($deployment->last_restart_at) {
            $timeline[] = [
                'type' => 'restart',
                'label' => "Restarted ({$deployment->restart_attempts} total)",
                'at' => $deployment->last_restart_at->toIso8601String(),
                'human' => $deployment->last_restart_at->diffForHumans(),
            ];
        }
        usort($timeline, fn ($a, $b) => $b['at'] <=> $a['at']);

        // Package allocation (product limits override template defaults)
        $template = $service->product->containerTemplate;
        $containerLimits = $service->product->getIncludedContainerLimits($template, $deployment);

        return response()->json([
            'status' => $deployment->status,
            'health_score' => $score,
            'incident_level' => $incidentLevel,
            'incident_message' => $incidentMessage,
            'restart_attempts' => $deployment->restart_attempts,
            'last_restart_at' => $deployment->last_restart_at?->toIso8601String(),
            'last_restart_human' => $deployment->last_restart_at?->diffForHumans(),
            'uptime_seconds' => $deployment->getUptimeSeconds(),
            'uptime_human' => $this->formatUptime($deployment->getUptimeSeconds()),
            'deployed_at_ts' => $deployment->deployed_at?->timestamp,
            'last_check_human' => $deployment->last_status_check_at?->diffForHumans() ?? 'Never',
            'node' => $deployment->node ? [
                'hostname' => $deployment->node->hostname,
                'region' => $deployment->node->region ?? 'N/A',
                'datacenter' => $deployment->node->datacenter ?? 'N/A',
                'ip' => $deployment->node->ip_address,
                'status' => $deployment->node->status,
            ] : null,
            'ssl_domains' => $deployment->domains->map(fn ($d) => [
                'domain' => $d->domain,
                'ssl_enabled' => $d->ssl_enabled,
                'status' => $d->status,
                'verified_at' => $d->verified_at?->format('Y-m-d'),
            ])->values(),
            'bandwidth' => [
                '1h' => ['rx' => (int) ($bw1h->rx ?? 0),  'tx' => (int) ($bw1h->tx ?? 0),  'peak' => (int) ($bw1h->peak ?? 0)],
                '24h' => ['rx' => (int) ($bw24h->rx ?? 0), 'tx' => (int) ($bw24h->tx ?? 0), 'peak' => (int) ($bw24h->peak ?? 0)],
                '7d' => ['rx' => (int) ($bw7d->rx ?? 0),  'tx' => (int) ($bw7d->tx ?? 0),  'peak' => (int) ($bw7d->peak ?? 0)],
            ],
            'activity_rate_bytes_per_min' => (int) $activityRate,
            'allocation' => [
                'cpu_cores' => $containerLimits['cpu'],
                'memory_mb' => $containerLimits['memory_mb'],
                'storage_gb' => $containerLimits['disk_gb'],
            ],
            'timeline' => $timeline,
            'restart_policy' => $deployment->restart_policy,
            'auto_restart' => $deployment->auto_restart,
            'selected_version' => $deployment->selected_version,
            'container_name' => $deployment->container_name,
            'assigned_port' => $deployment->assigned_port,
        ]);
    }

    /**
     * Bind a domain to a container
     */
    public function bindDomain(Service $service, Request $request): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return $this->domainsTabRedirect($service)->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $validator = Validator::make($request->all(), [
                'domain' => ['required', 'string', 'regex:'.$this->containerDomainRegex(), 'unique:container_domains,domain'],
            ]);

            if ($validator->fails()) {
                return $this->domainsTabRedirect($service)->withErrors($validator)->withInput();
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return $this->domainsTabRedirect($service)->withErrors(['error' => 'Container not deployed yet']);
            }

            $hostname = strtolower($request->domain);
            $nodeIp = $deployment->node->ip_address;
            $nginxService = new NginxProxyService;

            $platformDomain = app(DomainCloudflareDnsService::class)
                ->resolvePlatformDomainForHostname($service->user_id, $hostname);

            if ($platformDomain) {
                app(DomainCloudflareDnsService::class)->upsertARecord($platformDomain, $hostname, $nodeIp);
            }

            $dnsCorrect = $nginxService->checkDns($hostname, $nodeIp);

            $domain = ContainerDomain::create([
                'container_deployment_id' => $deployment->id,
                'domain' => $hostname,
                'status' => 'pending',
            ]);

            $nginxService->bind($domain);

            $message = "Domain {$domain->domain} bound successfully";
            if ($platformDomain) {
                $message .= '. DNS A record updated via managed DNS.';
            }
            $message .= $this->appendAutoSslMessage($nginxService, $domain, $service, $dnsCorrect, $nodeIp);

            return $this->domainsTabRedirect($service)->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Failed to bind domain for service {$service->id}: ".$e->getMessage());

            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Failed to bind domain: '.$e->getMessage()]);
        }
    }

    /**
     * Update a bound domain name.
     */
    public function updateDomain(Service $service, ContainerDomain $domain, Request $request): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($response = $this->assertContainerDomainOwnership($service, $domain)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'domain' => [
                'required',
                'string',
                'regex:'.$this->containerDomainRegex(),
                Rule::unique('container_domains', 'domain')->ignore($domain->id),
            ],
        ]);

        if ($validator->fails()) {
            return $this->domainsTabRedirect($service)->withErrors($validator)->withInput();
        }

        $newDomain = strtolower($request->domain);
        if ($newDomain === $domain->domain) {
            return $this->domainsTabRedirect($service)->with('success', 'Domain unchanged.');
        }

        try {
            $deployment = $service->containerDeployment;
            $nginxService = new NginxProxyService;

            $nginxService->removeProxyConfig($domain);
            $nginxService->cleanupSslCertificate($domain);

            $domain->update([
                'domain' => $newDomain,
                'status' => 'pending',
                'ssl_enabled' => false,
                'ssl_certificate_path' => null,
                'ssl_key_path' => null,
                'nginx_config_path' => null,
                'verified_at' => null,
                'error_message' => null,
            ]);

            $domain->refresh();
            $nginxService->bind($domain);

            $message = "Domain updated to {$domain->domain}";
            $message .= $this->appendAutoSslMessage(
                $nginxService,
                $domain,
                $service,
                $nginxService->checkDns($domain->domain, $deployment->node->ip_address),
                $deployment->node->ip_address
            );

            return $this->domainsTabRedirect($service)->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Failed to update domain for service {$service->id}: ".$e->getMessage());

            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Failed to update domain: '.$e->getMessage()]);
        }
    }

    /**
     * Unbind a domain from a container
     */
    public function unbindDomain(Service $service, ContainerDomain $domain): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($response = $this->assertContainerDomainOwnership($service, $domain)) {
            return $response;
        }

        try {
            $domainName = $domain->domain;

            $nginxService = new NginxProxyService;
            $nginxService->unbind($domain);

            return $this->domainsTabRedirect($service)->with('success', "Domain {$domainName} removed successfully");
        } catch (\Exception $e) {
            \Log::error("Failed to unbind domain for service {$service->id}: ".$e->getMessage());

            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Failed to remove domain: '.$e->getMessage()]);
        }
    }

    /**
     * Enable SSL for a domain
     */
    public function enableSsl(Service $service, ContainerDomain $domain): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($response = $this->assertContainerDomainOwnership($service, $domain)) {
            return $response;
        }

        try {
            if ($domain->status !== 'active') {
                return $this->domainsTabRedirect($service)->withErrors(['error' => 'Domain must be active to enable SSL']);
            }

            $nginxService = new NginxProxyService;
            $nginxService->enableSsl($domain);

            return $this->domainsTabRedirect($service)->with('success', "SSL enabled for {$domain->domain}");
        } catch (\Exception $e) {
            \Log::error("Failed to enable SSL for domain {$domain->domain}: ".$e->getMessage());

            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Failed to enable SSL: '.$e->getMessage()]);
        }
    }

    private function domainsTabRedirect(Service $service): RedirectResponse
    {
        return redirect()->route('customer.services.container.show', [
            'service' => $service,
            'tab' => 'domains',
        ]);
    }

    private function assertContainerDomainOwnership(Service $service, ContainerDomain $domain): ?RedirectResponse
    {
        if ($service->product?->type !== 'container_hosting') {
            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Service is not a container hosting service']);
        }

        if ($domain->container_deployment_id !== $service->containerDeployment?->id) {
            return $this->domainsTabRedirect($service)->withErrors(['error' => 'Domain does not belong to this service']);
        }

        return null;
    }

    private function containerDomainRegex(): string
    {
        return '/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)+[a-z]{2,}$/i';
    }

    private function appendAutoSslMessage(
        NginxProxyService $nginxService,
        ContainerDomain $domain,
        Service $service,
        bool $dnsCorrect,
        string $nodeIp
    ): string {
        if (! $dnsCorrect) {
            return " (Note: DNS is not yet pointing to {$nodeIp})";
        }

        try {
            $nginxService->enableSsl($domain);
            $domain->refresh();

            return ' SSL certificate issued successfully.';
        } catch (\Throwable $sslError) {
            \Log::warning("Auto SSL issuance failed for domain {$domain->domain}", [
                'service_id' => $service->id,
                'domain' => $domain->domain,
                'error' => $sslError->getMessage(),
            ]);

            return " Domain is active without SSL. Use 'Get SSL' to retry once DNS/propagation is ready.";
        }
    }

    public function createBackup(Service $service)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new ContainerBackupService;
            $backup = $backupService->createBackup($service, 'manual');

            return back()->with('success', "Backup '{$backup->backup_name}' created successfully.");
        } catch (\Exception $e) {
            \Log::error("Failed to create backup for service {$service->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Backup failed: '.$e->getMessage()]);
        }
    }

    public function restoreBackup(Service $service, ContainerBackup $backup)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new ContainerBackupService;
            $backupService->restoreBackup($backup);

            return back()->with('success', "Container restored from backup '{$backup->backup_name}'.");
        } catch (\Exception $e) {
            \Log::error("Failed to restore backup {$backup->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Restore failed: '.$e->getMessage()]);
        }
    }

    public function deleteBackup(Service $service, ContainerBackup $backup)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new ContainerBackupService;
            $backupService->deleteBackup($backup);

            return back()->with('success', "Backup '{$backup->backup_name}' deleted.");
        } catch (\Exception $e) {
            \Log::error("Failed to delete backup {$backup->id}: ".$e->getMessage());

            return back()->withErrors(['error' => 'Delete failed: '.$e->getMessage()]);
        }
    }

    public function destroy(Request $request, Service $service, CustomerServiceCancellationService $cancellation): RedirectResponse
    {
        $this->authorize('manageContainer', $service);

        $request->validate([
            'service_name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($service): void {
                    if ((string) $value !== (string) $service->name) {
                        $fail('The service name does not match. Type the exact name to confirm deletion.');
                    }
                },
            ],
        ], [
            'service_name.required' => 'Enter the service name to confirm deletion.',
        ]);

        try {
            $result = $cancellation->cancel(
                $service,
                auth()->user(),
                'Customer deleted service from container dashboard after confirming service name.'
            );

            return redirect()->route('customer.services.index')
                ->with($result['deprovisioned'] ? 'success' : 'warning', $result['message']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
