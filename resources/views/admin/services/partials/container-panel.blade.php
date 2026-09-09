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
        x-data="{ composeOpen: false }"
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

        <div class="flex flex-wrap gap-2 items-center">
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

            @include('services.partials.container-redeploy-modal')
            <a href="{{ route('admin.services.container.edit', $service) }}" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">
                Edit runtime
            </a>
            <a href="{{ route('admin.services.container.migrate', $service) }}" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">
                Migrate node
            </a>
        </div>

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

        @if (filled($deployment->docker_compose_content))
            <div>
                <button type="button" class="flex items-center justify-between w-full text-sm font-semibold text-slate-900 dark:text-white" @click="composeOpen = !composeOpen">
                    <span>Docker Compose</span>
                    <span class="text-slate-400" x-text="composeOpen ? 'Hide' : 'Show'"></span>
                </button>
                <pre x-show="composeOpen" x-cloak class="mt-3 bg-slate-950 text-emerald-300 p-4 rounded-lg text-xs overflow-x-auto max-h-96">{{ $deployment->docker_compose_content }}</pre>
            </div>
        @endif
    </div>

    <div class="mt-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Application console</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Same tools the customer has: logs, Git pull, files, terminal, env, doctor.</p>
        </div>
        @include('services.partials.container-console')
        @include('services.partials.container-console-scripts')
    </div>

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
