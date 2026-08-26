@if ($service->isContainerHosting() && $service->containerDeployment)
    @php
        $deployment = $service->containerDeployment;
        $template = $service->effectiveContainerTemplate();
        $limits = $service->product?->getIncludedContainerLimits($template, $deployment) ?? [
            'cpu' => (float) ($template?->required_cpu_cores ?? $deployment->cpu_limit ?? 0),
            'memory_mb' => (int) ($template?->required_ram_mb ?? $deployment->memory_limit_mb ?? 0),
            'disk_gb' => (float) ($template?->required_storage_gb ?? 0),
        ];
        $accessUrl = $deployment->getAccessUrl();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $repoUrl = trim((string) ($meta['source_repo_url'] ?? ''));
        $repoBranch = trim((string) ($meta['source_repo_branch'] ?? 'main')) ?: 'main';
        $stackLabel = $template?->name
            ?? (filled($meta['application_stack'] ?? null) ? (string) $meta['application_stack'] : null)
            ?? (filled($meta['language_slug'] ?? null) ? ucfirst((string) $meta['language_slug']) : 'Application');
        $statusStyles = match ($deployment->status) {
            'running' => ['dot' => 'bg-emerald-500', 'pulse' => true, 'badge' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300'],
            'stopped', 'suspended' => ['dot' => 'bg-amber-400', 'pulse' => false, 'badge' => 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300'],
            'deploying', 'pending' => ['dot' => 'bg-blue-500', 'pulse' => true, 'badge' => 'bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300'],
            'failed' => ['dot' => 'bg-red-500', 'pulse' => false, 'badge' => 'bg-red-100 dark:bg-red-950 text-red-800 dark:text-red-300'],
            default => ['dot' => 'bg-slate-400', 'pulse' => false, 'badge' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
        };
        $activeBackups = $deployment->relationLoaded('backups')
            ? $deployment->backups->whereNotIn('status', ['deleted'])->sortByDesc('created_at')
            : $deployment->backups()->whereNotIn('status', ['deleted'])->latest()->get();
        $domains = $deployment->relationLoaded('domains')
            ? $deployment->domains
            : $deployment->domains()->get();
    @endphp

    <div
        class="ui-card p-6 space-y-6"
        x-data="{
            logsOpen: false,
            composeOpen: false,
            logs: '',
            logsLoading: false,
            logsError: '',
            async loadLogs() {
                this.logsOpen = true;
                this.logsLoading = true;
                this.logsError = '';
                try {
                    const response = await fetch(@js(route('admin.services.container.logs', $service)));
                    const data = await response.json();
                    if (data.error) {
                        this.logsError = data.error;
                        this.logs = '';
                    } else {
                        this.logs = data.logs || 'No logs available';
                    }
                } catch (error) {
                    this.logsError = 'Failed to fetch logs';
                    this.logs = '';
                } finally {
                    this.logsLoading = false;
                }
            }
        }"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Application runtime</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $stackLabel }} on {{ $deployment->node?->hostname ?? $deployment->node?->name ?? 'unassigned node' }}</p>
            </div>
            <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusStyles['badge'] }}">
                <span class="relative flex h-2 w-2">
                    @if ($statusStyles['pulse'])
                        <span class="absolute inline-flex h-full w-full rounded-full {{ $statusStyles['dot'] }} opacity-75 animate-ping"></span>
                    @endif
                    <span class="relative inline-flex h-2 w-2 rounded-full {{ $statusStyles['dot'] }}"></span>
                </span>
                {{ ucfirst($deployment->status) }}
            </span>
        </div>

        @if ($deployment->node && $deployment->node->status === 'offline')
            <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3">
                <p class="text-sm font-semibold text-red-900 dark:text-red-100">Container host is offline</p>
                <p class="text-sm text-red-800 dark:text-red-200 mt-1">{{ $deployment->node->hostname ?? $deployment->node->name }} is not responding. Migrate this application to a healthy node.</p>
                <a href="{{ route('admin.services.container.migrate', $service) }}" class="inline-flex mt-3 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition">
                    Migrate now
                </a>
            </div>
        @endif

        @if ($deployment->status === 'failed' && filled($deployment->last_status_check_output))
            <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3">
                <p class="text-sm font-semibold text-red-900 dark:text-red-100">Last runtime check failed</p>
                <pre class="mt-2 text-xs font-mono text-red-800 dark:text-red-200 whitespace-pre-wrap break-all max-h-32 overflow-y-auto">{{ $deployment->last_status_check_output }}</pre>
            </div>
        @endif

        @if ($deployment->migrated_at)
            <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 px-4 py-3 text-sm text-blue-900 dark:text-blue-100">
                Last migrated {{ $deployment->migrated_at->format('M d, Y H:i') }}
                from {{ $deployment->migratedFromNode?->hostname ?? 'unknown node' }}
                ({{ ucfirst(str_replace('_', ' ', $deployment->migration_reason ?? 'manual')) }}).
            </div>
        @endif

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @if ($accessUrl)
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg sm:col-span-2">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Access URL</dt>
                    <dd class="mt-1 font-mono text-sm break-all">
                        <a href="{{ $accessUrl }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $accessUrl }}</a>
                    </dd>
                </div>
            @endif
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Container</dt>
                <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white break-all">{{ $deployment->container_name }}</dd>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Node</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                    @if ($deployment->node)
                        <a href="{{ route('admin.nodes.show', $deployment->node) }}" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $deployment->node->hostname ?? $deployment->node->name }}</a>
                    @else
                        <span class="text-slate-400">Not assigned</span>
                    @endif
                </dd>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Port</dt>
                <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white">{{ $deployment->assigned_port ?? '—' }}</dd>
            </div>
            @if ($repoUrl !== '')
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Git repository</dt>
                    <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white break-all">{{ $repoUrl }} <span class="text-slate-500 dark:text-slate-400">({{ $repoBranch }})</span></dd>
                </div>
            @endif
            @if ($deployment->deployed_at)
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Deployed</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->deployed_at->diffForHumans() }}</dd>
                </div>
            @endif
            @if ($deployment->last_status_check_at)
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last status check</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->last_status_check_at->diffForHumans() }}</dd>
                </div>
            @endif
            @if ($deployment->terminated_at)
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Terminated</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $deployment->terminated_at->diffForHumans() }}</dd>
                </div>
            @endif
        </dl>

        <div class="flex flex-wrap gap-2">
            @if ($deployment->status === 'pending')
                <form method="POST" action="{{ route('admin.services.container.provision', $service) }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Provision</button>
                </form>
            @elseif ($deployment->isRunning())
                <form method="POST" action="{{ route('admin.services.container.restart', $service) }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">Restart</button>
                </form>
                <form method="POST" action="{{ route('admin.services.container.suspend', $service) }}" data-confirm="Suspend this container? The application will stop until it is started again." data-confirm-title="Suspend container">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition">Suspend</button>
                </form>
            @elseif (in_array($deployment->status, ['stopped', 'suspended'], true))
                <form method="POST" action="{{ route('admin.services.container.start', $service) }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Start</button>
                </form>
            @endif

            <button type="button" @click="loadLogs()" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">
                View logs
            </button>
            <a href="{{ route('admin.services.container.edit', $service) }}" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">
                Edit runtime
            </a>
            <a href="{{ route('admin.services.container.migrate', $service) }}" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">
                Migrate node
            </a>
        </div>

        <form method="POST" action="{{ route('admin.services.container.redeploy', $service) }}" class="flex flex-col sm:flex-row sm:items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-700" data-confirm="Redeploy this container? Files in /app are kept unless you reset the database." data-confirm-title="Redeploy stack">
            @csrf
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-white">Redeploy stack</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Recreate the runtime from the current Git source and compose file.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="reset_database" value="1" class="rounded border-slate-300 dark:border-slate-600">
                Reset database
            </label>
            <button type="submit" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition">
                Redeploy
            </button>
        </form>

        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Resource Allocation</h3>
            <div class="grid grid-cols-3 gap-3">
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">CPU</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white mt-1">{{ $limits['cpu'] }} {{ (float) $limits['cpu'] == 1.0 ? 'core' : 'cores' }}</p>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">RAM</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white mt-1">{{ $limits['memory_mb'] }}MB</p>
                </div>
                <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Storage</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white mt-1">{{ $limits['disk_gb'] }}GB</p>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Resource usage (last 24 hours)</h3>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="h-40">
                    <canvas id="cpuChart"></canvas>
                </div>
                <div class="h-40">
                    <canvas id="memoryChart"></canvas>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Custom domains</h3>
            @if ($domains->isNotEmpty())
                <div class="space-y-2 mb-4">
                    @foreach ($domains as $domain)
                        @php
                            $domainStatus = match ($domain->status) {
                                'pending' => 'bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300',
                                'active' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300',
                                'failed' => 'bg-red-100 dark:bg-red-950 text-red-800 dark:text-red-300',
                                'removing' => 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300',
                                default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
                            };
                        @endphp
                        <div class="flex flex-col gap-3 p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <p class="font-mono text-sm text-slate-900 dark:text-white">{{ $domain->domain }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $domainStatus }}">{{ ucfirst($domain->status) }}</span>
                                        @if ($domain->hasSsl())
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">SSL</span>
                                        @elseif ($domain->canRequestSsl())
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">No SSL</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    @if ($domain->canRequestSsl())
                                        <form method="POST" action="{{ route('admin.services.container.domains.ssl', [$service, $domain]) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition">
                                                {{ $domain->error_message ? 'Retry SSL' : 'Enable SSL' }}
                                            </button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.services.container.domains.unbind', [$service, $domain]) }}" data-confirm="Remove {{ $domain->domain }} from this container?" data-confirm-title="Remove domain">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-950/40 text-xs font-medium rounded-lg transition">Remove</button>
                                    </form>
                                </div>
                            </div>
                            @php
                                $domainSetupError = $domain->error_message
                                    ? app(\App\Services\Provisioning\ContainerSslErrorPresenter::class)->present($domain)
                                    : null;
                            @endphp
                            @if ($domainSetupError)
                                <x-container-ssl-error
                                    :title="$domainSetupError['title']"
                                    :guidance="$domainSetupError['guidance']"
                                    :details="$domainSetupError['details']"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">No custom domains bound yet.</p>
            @endif

            <form method="POST" action="{{ route('admin.services.container.domains.bind', $service) }}" class="flex flex-col sm:flex-row gap-2">
                @csrf
                <input type="text" name="domain" placeholder="example.com" class="flex-1 px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-white rounded-lg text-sm" required>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Bind domain</button>
            </form>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Backups</h3>
            @if ($activeBackups->isNotEmpty())
                <div class="space-y-2 mb-4">
                    @foreach ($activeBackups as $backup)
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-3 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-sm">
                            <div>
                                <p class="font-mono text-slate-900 dark:text-white">{{ $backup->backup_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $backup->status === 'completed' ? 'Size: '.formatBytes($backup->size_bytes) : ucfirst($backup->status) }}
                                    · {{ $backup->created_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                @if ($backup->status === 'completed')
                                    <form method="POST" action="{{ route('admin.services.container.backups.restore', [$service, $backup]) }}" data-confirm="Restore this backup? The running application will be replaced." data-confirm-title="Restore backup">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition">Restore</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.services.container.backups.delete', [$service, $backup]) }}" data-confirm="Delete this backup permanently?" data-confirm-title="Delete backup">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1.5 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-950/40 text-xs font-medium rounded-lg transition">Delete</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">No backups yet.</p>
            @endif

            <form method="POST" action="{{ route('admin.services.container.backups.create', $service) }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-medium rounded-lg transition">Create backup</button>
            </form>
        </div>

        @if (filled($deployment->docker_compose_content))
            <div>
                <button type="button" class="flex items-center justify-between w-full text-sm font-semibold text-slate-900 dark:text-white" @click="composeOpen = !composeOpen">
                    <span>Docker Compose</span>
                    <span class="text-slate-400" x-text="composeOpen ? 'Hide' : 'Show'"></span>
                </button>
                <pre x-show="composeOpen" x-cloak class="mt-3 bg-slate-950 text-emerald-300 p-4 rounded-lg text-xs overflow-x-auto max-h-96">{{ $deployment->docker_compose_content }}</pre>
            </div>
        @endif

        <div x-show="logsOpen" x-cloak class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Recent logs</h3>
                <div class="flex items-center gap-2">
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-800 dark:hover:text-slate-200" @click="loadLogs()">Refresh</button>
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-800 dark:hover:text-slate-200" @click="logsOpen = false">Close</button>
                </div>
            </div>
            <pre class="bg-slate-950 p-4 rounded-lg text-xs overflow-x-auto max-h-96 whitespace-pre-wrap break-all" :class="logsError ? 'text-red-400' : 'text-slate-200'" x-text="logsLoading ? 'Loading logs...' : (logsError ? ('Error: ' + logsError) : logs)"></pre>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        let cpuChart = null;
        let memoryChart = null;

        function chartTheme() {
            const isDark = document.documentElement.classList.contains('dark');
            return {
                tick: isDark ? '#94a3b8' : '#64748b',
                grid: isDark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(15, 23, 42, 0.08)',
            };
        }

        function initializeCharts() {
            fetch(@js(route('admin.services.container.metrics', $service)))
                .then(response => response.json())
                .then(data => {
                    if (data.labels && data.labels.length > 0) {
                        renderCpuChart(data);
                        renderMemoryChart(data);
                    }
                })
                .catch(error => console.error('Failed to load metrics:', error));
        }

        function renderCpuChart(data) {
            const ctx = document.getElementById('cpuChart').getContext('2d');
            const theme = chartTheme();
            if (cpuChart) cpuChart.destroy();
            cpuChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'CPU %',
                        data: data.cpu,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.12)',
                        borderWidth: 1.5,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top', labels: { color: theme.tick } } },
                    scales: {
                        x: { ticks: { color: theme.tick }, grid: { color: theme.grid } },
                        y: { beginAtZero: true, max: 100, ticks: { color: theme.tick }, grid: { color: theme.grid } }
                    }
                }
            });
        }

        function renderMemoryChart(data) {
            const ctx = document.getElementById('memoryChart').getContext('2d');
            const theme = chartTheme();
            if (memoryChart) memoryChart.destroy();
            memoryChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Memory (MB)',
                        data: data.memory,
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.12)',
                        borderWidth: 1.5,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top', labels: { color: theme.tick } } },
                    scales: {
                        x: { ticks: { color: theme.tick }, grid: { color: theme.grid } },
                        y: { beginAtZero: true, ticks: { color: theme.tick }, grid: { color: theme.grid } }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', initializeCharts);
    </script>
    @endpush
@elseif ($service->isContainerHosting())
    <div class="ui-card p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Application runtime</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2">No container has been provisioned for this service yet.</p>
        @if (in_array($service->status->value, ['pending', 'provisioning', 'failed'], true))
            <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="mt-4">
                @csrf
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                    {{ $service->status->value === 'provisioning' ? 'Retry provisioning' : 'Provision' }}
                </button>
            </form>
        @endif
    </div>
@endif
