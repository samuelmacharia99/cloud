        @if ($deployment)
            @php
                // Shared with the Alpine allow-list in container-console-scripts.
                $containerTabs = $containerTabs ?? \App\Support\ContainerConsoleTabs::resolve(
                    ! empty($supportsOllamaChat),
                    ! empty($supportsGitRepository),
                    ! empty($supportsPhpExtensions),
                );
                $initialTab = \App\Support\ContainerConsoleTabs::initial($containerTabs, request('tab'));
            @endphp
            <!-- Tab Navigation -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg mb-8 pb-20 md:pb-0" x-data="containerTabs(@js($initialTab))" x-init="init()" @container-set-tab.window="setTab($event.detail)">
                <div class="border-b border-slate-200 dark:border-slate-700 px-4 pt-4">
                    <label class="md:hidden block text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">Section</label>
                    <select :value="activeTab" @change="setTab($event.target.value)" class="md:hidden w-full mb-4 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm">
                        <optgroup label="App">
                            <option value="overview">Overview</option>
                            <option value="environment">Environment</option>
                            <option value="files">Files</option>
                            @if (!empty($supportsOllamaChat))
                                <option value="chat">Chat</option>
                            @endif
                            <option value="terminal">Terminal</option>
                            @if (!empty($supportsGitRepository))
                                <option value="github">Git</option>
                            @endif
                            @if (!empty($supportsPhpExtensions))
                                <option value="php-extensions">PHP Extensions</option>
                            @endif
                        </optgroup>
                        <optgroup label="Data">
                            <option value="database">Database</option>
                            <option value="backups">Backups</option>
                        </optgroup>
                        <optgroup label="Network">
                            <option value="domains">Domains</option>
                        </optgroup>
                        <optgroup label="Ops">
                            <option value="logs">Logs</option>
                            <option value="cron">Cron Jobs</option>
                        </optgroup>
                        <optgroup label="Help">
                            <option value="documentation">Documentation</option>
                        </optgroup>
                    </select>

                    {{-- Only real tabs are clickable. Group names are not tabs (that confused the IA). --}}
                    <nav class="hidden md:block overflow-x-auto pb-1 -mx-1 px-1" role="tablist" aria-label="Application console sections">
                        <div class="flex items-center gap-1 min-w-max">
                            @foreach ([
                                ['overview', 'Overview'],
                                ['environment', 'Environment'],
                                ['files', 'Files'],
                            ] as [$tabKey, $tabLabel])
                                <button type="button" @click="setTab('{{ $tabKey }}')" :class="activeTab === '{{ $tabKey }}' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === '{{ $tabKey }}'">{{ $tabLabel }}</button>
                            @endforeach
                            @if (!empty($supportsOllamaChat))
                                <button type="button" @click="setTab('chat')" :class="activeTab === 'chat' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === 'chat'">Chat</button>
                            @endif
                            <button type="button" @click="setTab('terminal')" :class="activeTab === 'terminal' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === 'terminal'">Terminal</button>
                            @if (!empty($supportsGitRepository))
                                <button type="button" @click="setTab('github')" :class="activeTab === 'github' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === 'github'">Git</button>
                            @endif
                            @if (!empty($supportsPhpExtensions))
                                <button type="button" @click="setTab('php-extensions')" :class="activeTab === 'php-extensions' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === 'php-extensions'">PHP Extensions</button>
                            @endif

                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700 shrink-0" aria-hidden="true"></span>

                            @foreach ([['database', 'Database'], ['backups', 'Backups'], ['domains', 'Domains']] as [$tabKey, $tabLabel])
                                <button type="button" @click="setTab('{{ $tabKey }}')" :class="activeTab === '{{ $tabKey }}' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === '{{ $tabKey }}'">{{ $tabLabel }}</button>
                            @endforeach

                            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700 shrink-0" aria-hidden="true"></span>

                            @foreach ([['logs', 'Logs'], ['cron', 'Cron'], ['documentation', 'Docs']] as [$tabKey, $tabLabel])
                                <button type="button" @click="setTab('{{ $tabKey }}')" :class="activeTab === '{{ $tabKey }}' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'border-b-2 border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-3 py-2.5 font-medium transition text-sm whitespace-nowrap" role="tab" :aria-selected="activeTab === '{{ $tabKey }}'">{{ $tabLabel }}</button>
                            @endforeach
                        </div>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="p-8">
                    <!-- Overview Tab -->
                    <div x-show="activeTab === 'overview'" class="space-y-8">
                        @if (is_array(data_get($service->service_meta, 'node_workloads.notes')) && data_get($service->service_meta, 'node_workloads.notes') !== [])
                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                                <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">This host is running the API</p>
                                @foreach (data_get($service->service_meta, 'node_workloads.notes') as $note)
                                    <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">{{ $note }}</p>
                                @endforeach
                            </div>
                        @endif
                        <!-- Quick Actions -->
                        <div class="flex gap-3 flex-wrap items-center">
                            @if ($deployment->isRunning())
                                <form method="POST" action="{{ container_route('stop', $service) }}" style="display:inline;" data-confirm="Stop this app? It will be unavailable until you start it again." data-confirm-title="Stop app">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium transition">
                                        Stop
                                    </button>
                                </form>

                                <form method="POST" action="{{ container_route('restart', $service) }}" style="display:inline;" data-confirm="Restart the app? There will be brief downtime." data-confirm-title="Restart app">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                        Restart
                                    </button>
                                </form>
                            @elseif (in_array($deployment->status, ['stopped', 'failed']))
                                <form method="POST" action="{{ container_route('start', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        Start
                                    </button>
                                </form>
                            @endif

                            @php
                                $accessUrl = $deployment->getAccessUrl();
                            @endphp
                            @if ($deployment->isRunning() && $accessUrl)
                                <a
                                    href="{{ $accessUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition"
                                    onclick="window.open(this.href, '_blank'); return false;"
                                >
                                    Visit service
                                </a>
                            @else
                                <span class="px-5 py-2 bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 rounded-lg font-medium cursor-not-allowed" title="{{ $deployment->isRunning() ? 'No public URL is available yet' : 'Start the app to visit it' }}">
                                    Visit service
                                </span>
                            @endif

                            @if (!empty($hermesDashboardPanel['url']) && !empty($hermesDashboardPanel['container_running']))
                                <a
                                    href="{{ $hermesDashboardPanel['url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition"
                                    onclick="window.open(this.href, '_blank'); return false;"
                                >
                                    Open dashboard
                                </a>
                            @endif

                            @if (($containerConsoleContext ?? 'customer') === 'customer')
                                @if ($service->isWordPressContainer())
                                    <a href="{{ route('customer.services.wordpress-admin', $service) }}" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                        WP Admin
                                    </a>
                                @else
                                    <a href="{{ route('customer.services.upgrade', $service) }}" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                        Change plan
                                    </a>
                                @endif
                            @endif
                        </div>

                        @include('customer.services.partials.staging')

                        @include('customer.services.partials.overview-quick-links')

                        @if (!empty($hermesDashboardPanel))
                            @include('customer.services.partials.hermes-dashboard')
                        @endif

                        @if (!empty($isLaravelTemplate))
                            @include('customer.services.partials.laravel-setup')
                        @endif

                        @include('customer.services.partials.enhanced-stats')
                    </div>

                    <!-- Environment Tab -->
                    <template x-if="hasVisited('environment')">
                        <div x-show="activeTab === 'environment'">
                            @include('customer.services.partials.environment')
                        </div>
                    </template>

                    <!-- Files Tab -->
                    <template x-if="hasVisited('files')">
                        <div x-show="activeTab === 'files'">
                            @include('customer.services.partials.file-manager')
                        </div>
                    </template>

                    <!-- Chat Tab -->
                    @if (!empty($supportsOllamaChat))
                        <template x-if="hasVisited('chat')">
                            <div x-show="activeTab === 'chat'">
                                @include('customer.services.partials.ollama-chat')
                            </div>
                        </template>
                    @endif

                    <!-- Terminal Tab -->
                    <template x-if="hasVisited('terminal')">
                        <div x-show="activeTab === 'terminal'" class="-mx-8 -mb-8">
                            @include('customer.services.partials.terminal')
                        </div>
                    </template>

                    <!-- Backups Tab -->
                    <template x-if="hasVisited('backups')">
                        <div x-show="activeTab === 'backups'">
                        <div class="space-y-6">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                <div>
                                    <h3 class="text-xl font-bold text-slate-900 dark:text-white">Backups</h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                        Manual and scheduled archives of your application files and data volumes.
                                    </p>
                                </div>
                                <form method="POST" action="{{ container_route('backups.create', $service) }}" style="display:inline;" data-confirm="Queue a backup now? It runs in the background with little or no downtime (refresh this tab for status)." data-confirm-title="Create backup">
                                    @csrf
                                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        Create backup
                                    </button>
                                </form>
                            </div>

                            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-100 space-y-1">
                                <p><strong>Background job:</strong> backups are queued so large sites are not killed by the web server’s 30s timeout. Refresh this tab to watch pending → running → completed.</p>
                                <p><strong>Faster backups:</strong> archives run live (no stop/start) and upload straight from the application server to Hetzner when configured — no double hop through the app server. Cache/temp dirs are skipped.</p>
                                <p>
                                    <strong>Scheduled backups:</strong> running containers are backed up automatically about every 24 hours.
                                    @if (! empty($scheduledBackupDue))
                                        Next window after your latest backup: {{ $scheduledBackupDue->format('M j, Y g:i A') }}.
                                    @else
                                        Create a backup or wait for the first scheduled run once the app is running.
                                    @endif
                                </p>
                            </div>

                            @php
                                $backups = $deployment->backups()->whereNotIn('status', ['deleted'])->orderByDesc('created_at')->get();
                            @endphp

                            @if ($backups->count() > 0)
                                <div class="space-y-3">
                                    @foreach ($backups as $backup)
                                        <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                            <div>
                                                <p class="font-mono font-semibold text-slate-900 dark:text-white">{{ $backup->backup_name }}</p>
                                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                                    @if ($backup->status === 'completed')
                                                        Size: {{ formatBytes($backup->size_bytes) }} • {{ $backup->created_at->diffForHumans() }}
                                                        @if ($backup->type)
                                                            • <span class="capitalize">{{ $backup->type }}</span>
                                                        @endif
                                                        @if (($backup->storage_driver ?? 'node') === 'hetzner')
                                                            • Storage Box
                                                        @endif
                                                    @elseif (in_array($backup->status, ['pending', 'running'], true))
                                                        Status: {{ ucfirst($backup->status) }} — in progress, refresh shortly
                                                    @elseif ($backup->status === 'failed')
                                                        Status: Failed
                                                        @if ($backup->error_message)
                                                            • <span class="text-red-600 dark:text-red-400">{{ \Illuminate\Support\Str::limit($backup->error_message, 120) }}</span>
                                                        @endif
                                                    @else
                                                        Status: {{ ucfirst($backup->status) }}
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="flex gap-2">
                                                @if ($backup->status === 'completed')
                                                    <form method="POST" action="{{ container_route('backups.restore', $service, $backup) }}" style="display:inline;" data-confirm="Restore this backup? Current application data will be replaced and the app will restart." data-confirm-title="Restore backup">
                                                        @csrf
                                                        <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                                            Restore
                                                        </button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ container_route('backups.delete', $service, $backup) }}" style="display:inline;" data-confirm="Delete this backup permanently?" data-confirm-title="Delete backup">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600">
                                    <p class="text-slate-600 dark:text-slate-400">No backups yet. Create one to protect your data.</p>
                                </div>
                            @endif
                        </div>
                        </div>
                    </template>

                    <!-- Domains Tab -->
                    <template x-if="hasVisited('domains')">
                        <div x-show="activeTab === 'domains'">
                        <div class="space-y-6">
                            @php
                                $domainTemplate = $service->effectiveContainerTemplate() ?? $service->product?->containerTemplate;
                                $isApiOnlyNode = \App\Services\Provisioning\ContainerNodeWorkloadTopologyService::isApiOnly($service);
                                $apiEndpointDomain = $isApiOnlyNode
                                    ? $deployment->domains->firstWhere('purpose', \App\Models\ContainerDomain::PURPOSE_API)
                                    : null;
                                $apiManagedDomain = $apiEndpointDomain
                                    ? app(\App\Services\Dns\DomainCloudflareDnsService::class)
                                        ->resolvePlatformDomainForHostname((int) $service->user_id, $apiEndpointDomain->domain)
                                    : null;
                            @endphp
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Custom Domains</h3>
                            </div>

                            @if ($isApiOnlyNode)
                                <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-5 dark:border-indigo-800 dark:bg-indigo-950/30">
                                    <h4 class="font-semibold text-indigo-950 dark:text-indigo-100">Public API endpoint</h4>
                                    <p class="mt-1 text-sm text-indigo-800 dark:text-indigo-200">
                                        Use a dedicated hostname for mobile apps and external clients. Managed domains receive their A record automatically; external domains must point to the host IP below.
                                    </p>

                                    @if ($apiEndpointDomain)
                                        <div class="mt-4 rounded-lg border border-indigo-200 bg-white p-4 dark:border-indigo-700 dark:bg-slate-900">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <a href="{{ 'https://'.$apiEndpointDomain->domain }}" target="_blank" rel="noopener"
                                                        class="font-mono font-semibold text-indigo-700 hover:underline dark:text-indigo-300">
                                                        {{ ($apiEndpointDomain->ssl_enabled ? 'https' : 'http').'://'.$apiEndpointDomain->domain }}
                                                    </a>
                                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                        @if ($apiEndpointDomain->ssl_enabled)
                                                            HTTPS ready.
                                                        @elseif ($apiEndpointDomain->status === 'active')
                                                            HTTP routing ready; issue SSL after DNS resolves.
                                                        @else
                                                            Endpoint setup needs attention. Review the error below or remove it and try again.
                                                        @endif
                                                    </p>
                                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                        @if ($apiManagedDomain)
                                                            Managed DNS points this hostname to {{ $deployment->node->ip_address }}.
                                                        @else
                                                            External DNS: create an A record for {{ $apiEndpointDomain->domain }} pointing to {{ $deployment->node->ip_address }}.
                                                        @endif
                                                    </p>
                                                </div>
                                                @if ($apiEndpointDomain->ssl_enabled)
                                                    <button type="button"
                                                        onclick="navigator.clipboard?.writeText(@js('EXPO_PUBLIC_API_URL=https://'.$apiEndpointDomain->domain))"
                                                        class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700">
                                                        Copy Expo variable
                                                    </button>
                                                @endif
                                            </div>
                                            @if ($apiEndpointDomain->ssl_enabled)
                                                <code class="mt-3 block break-all rounded bg-slate-100 px-3 py-2 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">API_BASE_URL=https://{{ $apiEndpointDomain->domain }}</code>
                                                <code class="mt-3 block break-all rounded bg-slate-100 px-3 py-2 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">EXPO_PUBLIC_API_URL=https://{{ $apiEndpointDomain->domain }}</code>
                                            @endif
                                        </div>
                                    @else
                                        <form method="POST" action="{{ container_route('domains.bind', $service) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                                            @csrf
                                            <input type="hidden" name="purpose" value="api">
                                            <input type="text" name="domain" value="{{ old('purpose') === 'api' ? old('domain') : '' }}"
                                                placeholder="api.example.com" autocomplete="off"
                                                class="flex-1 rounded-lg border-slate-300 px-4 py-2 font-mono dark:border-slate-600 dark:bg-slate-800 dark:text-white" required>
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2 font-medium text-white hover:bg-indigo-700">
                                                Configure API domain
                                            </button>
                                        </form>
                                    @endif
                                </section>
                            @endif

                            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-2">
                                <p class="text-sm text-blue-800 dark:text-blue-200">
                                    <strong>DNS setup:</strong> Point your domain's A record to
                                    <code class="font-mono">{{ $deployment->node->ip_address }}</code>
                                    before binding — or use <strong>managed DNS</strong> when you registered the domain with Talksasa (A records are created automatically on bind).
                                </p>
                            </div>

                            @if ($deployment->domains()->count() > 0)
                                <div class="space-y-3">
                                    @foreach ($deployment->domains as $domain)
                                        <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600" x-data="{ editing: false }">
                                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <div x-show="!editing">
                                                        <p class="font-mono font-semibold text-slate-900 dark:text-white break-all">{{ $domain->domain }}</p>
                                                        <div class="flex flex-wrap items-center gap-2 mt-2">
                                                            @php
                                                                $statusColor = match($domain->status) {
                                                                    'pending' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                                                    'active' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                                                    'failed' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                                                    default => 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-200',
                                                                };
                                                            @endphp
                                                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $statusColor }}">
                                                                {{ ucfirst($domain->status) }}
                                                            </span>
                                                            @if ($domain->isApiEndpoint())
                                                                <span class="px-2 py-1 rounded text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">API endpoint</span>
                                                            @endif
                                                            @if ($domain->ssl_enabled && $domain->status === 'active')
                                                                <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">🔒 SSL</span>
                                                            @elseif ($domain->canRequestSsl())
                                                                <span class="px-2 py-1 rounded text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">No SSL</span>
                                                            @endif
                                                            @if (($containerConsoleContext ?? 'customer') === 'customer')
                                                                @php
                                                                    $platformDomain = app(\App\Services\Dns\DomainCloudflareDnsService::class)
                                                                        ->resolvePlatformDomainForHostname(auth()->id(), $domain->domain);
                                                                @endphp
                                                                @if($platformDomain?->cloudflare_dns_enabled)
                                                                    <a href="{{ route('customer.domains.dns.index', $platformDomain) }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">Manage DNS →</a>
                                                                @endif
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <form x-show="editing" x-cloak method="POST" action="{{ container_route('domains.update', $service, $domain) }}" class="flex flex-col sm:flex-row gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="text" name="domain" value="{{ old('domain', $domain->domain) }}" class="flex-1 px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg dark:bg-slate-800 dark:text-white font-mono text-sm" required>
                                                        <div class="flex gap-2">
                                                            <button type="submit" class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                                            <button type="button" @click="editing = false" class="px-3 py-2 bg-slate-200 dark:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm rounded hover:bg-slate-300 dark:hover:bg-slate-500">Cancel</button>
                                                        </div>
                                                    </form>
                                                </div>
                                                <div class="flex flex-wrap gap-2 shrink-0">
                                                    @unless ($domain->isApiEndpoint())
                                                        <button type="button" x-show="!editing" @click="editing = true" class="px-3 py-1 bg-slate-200 dark:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm rounded hover:bg-slate-300 dark:hover:bg-slate-500">
                                                            Edit
                                                        </button>
                                                    @endunless
                                                    @if ($domain->canRequestSsl())
                                                        <form method="POST" action="{{ container_route('domains.ssl', $service, $domain) }}" class="inline">
                                                            @csrf
                                                            <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                                                {{ $domain->error_message ? 'Retry SSL' : 'Get SSL' }}
                                                            </button>
                                                        </form>
                                                    @endif
                                                    <form method="POST" action="{{ container_route('domains.unbind', $service, $domain) }}" class="inline" onsubmit="return confirm('Remove {{ $domain->domain }} from this app? This also removes routing and SSL for that hostname.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                                                            Remove
                                                        </button>
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
                                                    class="mt-4"
                                                    :title="$domainSetupError['title']"
                                                    :guidance="$domainSetupError['guidance']"
                                                    :details="$domainSetupError['details']"
                                                />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-slate-600 dark:text-slate-400">No custom domains bound yet. Add one below after DNS is configured.</p>
                            @endif

                            <form method="POST" action="{{ container_route('domains.bind', $service) }}" class="flex flex-col sm:flex-row gap-2">
                                @csrf
                                <input type="hidden" name="purpose" value="web">
                                <input type="text" name="domain" value="{{ old('domain') }}" placeholder="example.com" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg dark:bg-slate-700 dark:text-white font-mono" required>
                                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                    Add Domain
                                </button>
                            </form>
                        </div>
                        </div>
                    </template>

                    <!-- Database Tab -->
                    <template x-if="hasVisited('database')">
                        <div x-show="activeTab === 'database'">
                        <div class="space-y-6">
                            @if(empty($databaseConsoleEnabled))
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600">
                                    <p class="text-slate-600 dark:text-slate-400">Database console is disabled by administrator.</p>
                                </div>
                            @elseif(!empty($databaseContext['available']))
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4" x-data="{ showDbPassword: false }">
                                    <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                        <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Type</p>
                                        <p class="font-semibold text-slate-900 dark:text-white">{{ strtoupper($databaseContext['type']) }}</p>
                                    </div>
                                    <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                        <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Host</p>
                                        <p class="font-mono text-slate-900 dark:text-white">{{ $databaseContext['host'] }}:{{ $databaseContext['port'] }}</p>
                                    </div>
                                    <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                        <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Database</p>
                                        <p class="font-mono text-slate-900 dark:text-white">{{ $databaseContext['database'] }}</p>
                                    </div>
                                    <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                        <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Username</p>
                                        <p class="font-mono text-slate-900 dark:text-white">{{ $databaseContext['username'] }}</p>
                                    </div>
                                    <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600 md:col-span-2">
                                        <div class="flex items-center justify-between gap-2 mb-1">
                                            <p class="text-xs uppercase text-slate-500 dark:text-slate-400">Password</p>
                                            @if(!empty($databaseContext['password']))
                                                <div class="flex items-center gap-2">
                                                    <button type="button" @click="showDbPassword = !showDbPassword" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                                        <span x-text="showDbPassword ? 'Hide' : 'Show'"></span>
                                                    </button>
                                                    <button type="button" data-copy-value="{{ $databaseContext['password'] }}" onclick="navigator.clipboard.writeText(this.dataset.copyValue)" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                                        Copy
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                        <p class="font-mono text-slate-900 dark:text-white break-all">
                                            @if(!empty($databaseContext['password']))
                                                <span x-show="showDbPassword">{{ $databaseContext['password'] }}</span>
                                                <span x-show="!showDbPassword">{{ $databaseContext['password_masked'] }}</span>
                                            @elseif(!empty($databaseContext['redacted']))
                                                <span class="text-slate-500">Hidden in the operator console.</span>
                                            @else
                                                <span class="text-slate-500">Not available — redeploy to regenerate credentials.</span>
                                            @endif
                                        </p>
                                    </div>
                                    @if(!empty($databaseContext['connection']))
                                        <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600 md:col-span-2">
                                            <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Connection (from your app)</p>
                                            <p class="font-mono text-sm text-slate-900 dark:text-white break-all">{{ $databaseContext['connection'] }}</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex items-center gap-4 flex-wrap" x-data="{ testing: false, syncing: false, result: null }">
                                    <button type="button"
                                        @click="testing = true; result = null; postDatabaseAction('{{ container_route('database.test', $service) }}').then(data => { result = data; testing = false; })"
                                        :disabled="testing || syncing"
                                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white rounded-lg font-medium transition inline-flex items-center gap-2">
                                        <svg x-show="testing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                        <svg x-show="!testing" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.914-2.121-1.757 1.757a4.5 4.5 0 0 0-1.242-7.244l4.5-4.5a4.5 4.5 0 0 1 6.364 6.364l-1.757 1.757" /></svg>
                                        <span x-text="testing ? 'Testing...' : 'Test Connection'"></span>
                                    </button>
                                    <template x-if="result && !result.success">
                                        <button type="button"
                                            @click="syncing = true; postDatabaseAction('{{ container_route('database.sync', $service) }}').then(data => { result = data; syncing = false; })"
                                            :disabled="syncing"
                                            class="px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-lg font-medium transition inline-flex items-center gap-2">
                                            <svg x-show="syncing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                            <svg x-show="!syncing" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                            <span x-text="syncing ? 'Syncing...' : 'Repair Credentials'"></span>
                                        </button>
                                    </template>
                                    <template x-if="result">
                                        <div class="flex items-center gap-2 text-sm">
                                            <template x-if="result.success">
                                                <span class="inline-flex items-center gap-1 text-green-700 dark:text-green-400">
                                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                    <span x-text="result.message + (result.latency_ms ? ' (' + result.latency_ms + 'ms)' : '')"></span>
                                                </span>
                                            </template>
                                            <template x-if="!result.success">
                                                <span class="inline-flex items-center gap-1 text-red-700 dark:text-red-400">
                                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                                    <span x-text="result.message"></span>
                                                </span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                @php
                                    $dbStackSlug = $service->effectiveContainerTemplate()?->slug
                                        ?? $service->product?->containerTemplate?->slug;
                                    $dbIsPostgres = ($databaseContext['type'] ?? null) === 'postgresql';
                                    $dbSchemaIsYours = in_array(
                                        $dbStackSlug,
                                        \App\Services\Provisioning\ContainerDoctorService::STACKS_WITH_APPLICATION_OWNED_SCHEMA,
                                        true,
                                    );
                                @endphp
                                <p class="text-sm text-slate-600 dark:text-slate-400">
                                    Database credentials are provisioned automatically on deploy and redeploy. Connect from your app on host <code class="font-mono">{{ $databaseContext['host'] }}</code>, port <code class="font-mono">{{ $databaseContext['port'] }}</code>.
                                    @if ($dbStackSlug === 'laravel')
                                        Tick <strong>Reset database</strong> on redeploy to wipe data and auto-update <code class="font-mono">/app/.env</code> plus migrations when the app is already installed.
                                    @endif
                                </p>

                                @if ($dbSchemaIsYours)
                                    <p class="text-sm text-slate-600 dark:text-slate-400">
                                        The tables themselves come from your own migration step, which this platform does not run. Create them from the <strong>Terminal</strong> tab, or import a dump below. An empty database still passes <strong>Test Connection</strong>, because the credentials are valid either way.
                                    </p>
                                @endif

                                <div class="p-4 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/50">
                                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-2">Import SQL dump</h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">
                                        Upload a <code class="font-mono">.sql</code> file to load tables and data into this service database (max {{ $dbImportMaxMb }} MB). Large dumps are uploaded in small chunks so they are not blocked by PHP’s {{ $dbImportPhpLimitLabel ?? 'upload' }} limit. Existing tables with the same names may be overwritten.
                                        @if ($dbIsPostgres)
                                            The file is piped into <code class="font-mono">psql</code> and stops at the first error. Plain SQL only: export with <code class="font-mono">pg_dump --format=plain</code>, since a <code class="font-mono">-Fc</code> archive is not accepted here.
                                        @else
                                            DirectAdmin dumps that <code class="font-mono">CREATE DATABASE</code> / <code class="font-mono">USE</code> another name are rewritten into this sidecar.
                                        @endif
                                    </p>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <input type="file" id="db-import-file" accept=".sql,text/plain" class="text-sm text-slate-700 dark:text-slate-300">
                                        <button type="button" onclick="importDatabaseSql()" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium transition">
                                            Import SQL
                                        </button>
                                        <span id="db-import-status" class="text-sm text-slate-500 dark:text-slate-400"></span>
                                    </div>
                                    <pre id="db-import-output" class="mt-3 hidden bg-slate-900 text-slate-200 p-3 rounded-lg overflow-auto max-h-48 text-xs"></pre>
                                </div>

                                <div class="p-4 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800/40" x-data="dbTableBrowser(@js($databaseContext['type'] ?? 'mysql'))">
                                    <div class="flex items-center justify-between gap-3 mb-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Table browser</h3>
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">List tables and peek at rows without leaving the console.</p>
                                        </div>
                                        <button type="button" @click="loadTables()" :disabled="loading" class="px-3 py-1.5 text-sm rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 disabled:opacity-50">
                                            <span x-text="loading ? 'Loading…' : 'Refresh tables'"></span>
                                        </button>
                                    </div>
                                    <p x-show="error" class="text-sm text-red-600 dark:text-red-400 mb-2" x-text="error"></p>
                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                                        <ul class="lg:col-span-1 max-h-64 overflow-auto rounded-lg border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-800">
                                            <template x-if="!tables.length && !loading">
                                                <li class="p-3 text-sm text-slate-500">No tables loaded yet.</li>
                                            </template>
                                            <template x-for="table in tables" :key="table">
                                                <li>
                                                    <button type="button" @click="previewTable(table)" class="w-full text-left px-3 py-2 text-sm font-mono hover:bg-slate-50 dark:hover:bg-slate-700/60" :class="selected === table ? 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300' : 'text-slate-800 dark:text-slate-200'" x-text="table"></button>
                                                </li>
                                            </template>
                                        </ul>
                                        <pre class="lg:col-span-2 bg-slate-900 text-slate-200 p-3 rounded-lg overflow-auto max-h-64 text-xs" x-text="preview || 'Select a table to preview up to 25 rows.'"></pre>
                                    </div>
                                </div>

                                <div class="p-4 rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700">
                                    <p class="text-sm text-amber-900 dark:text-amber-200">
                                        @if ($dbIsPostgres)
                                            Read-only SQL console: only <code>SELECT</code> and <code>EXPLAIN</code> reach Postgres. <code>SHOW TABLES</code> and <code>DESCRIBE table</code> are translated to their <code>pg_catalog</code> equivalents for you.
                                        @else
                                            Read-only SQL console: only <code>SELECT</code>, <code>SHOW</code>, <code>DESCRIBE</code>, and <code>EXPLAIN</code> are allowed.
                                        @endif
                                    </p>
                                </div>

                                <div>
                                    <label for="db-query" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SQL Query</label>
                                    <textarea id="db-query" rows="5" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white p-3 font-mono text-sm" placeholder="SELECT * FROM table_name LIMIT 20"></textarea>
                                    <div class="mt-3 flex items-center gap-3">
                                        <button type="button" onclick="runDatabaseQuery('text')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                            Run Read-Only Query
                                        </button>
                                        <button type="button" onclick="runDatabaseQuery('csv')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                            Export CSV
                                        </button>
                                        <button type="button" onclick="loadDatabaseHistory()" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white rounded-lg font-medium transition">
                                            Refresh History
                                        </button>
                                        <span id="db-query-status" class="text-sm text-slate-500 dark:text-slate-400"></span>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Result</label>
                                    <pre id="db-query-output" class="bg-slate-900 text-slate-200 p-4 rounded-lg overflow-auto max-h-96 text-xs">No query executed yet.</pre>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Query History (Recent)</label>
                                    <div id="db-query-history" class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-600 divide-y divide-slate-200 dark:divide-slate-700">
                                        <div class="p-3 text-sm text-slate-500 dark:text-slate-400">No history loaded yet.</div>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600 space-y-3">
                                    <p class="text-slate-600 dark:text-slate-400">No database sidecar is configured for this service.</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400 max-w-lg mx-auto">
                                        Redeploy to auto-provision MySQL for Laravel/PHP apps, or order a new application hosting plan with a database selected during tech stack setup.
                                    </p>
                                </div>
                            @endif
                        </div>
                        </div>
                    </template>

                    @if (!empty($supportsGitRepository))
                        <!-- GitHub Tab -->
                        <template x-if="hasVisited('github')">
                            <div x-show="activeTab === 'github'">
                                @include('customer.services.partials.git-repository')
                            </div>
                        </template>
                    @endif

                    <!-- Logs Tab -->
                    <template x-if="hasVisited('logs')">
                        <div x-show="activeTab === 'logs'">
                            <div class="space-y-4">
                                @include('customer.services.partials.container-doctor')

                                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/80 overflow-hidden">
                                    <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 flex flex-wrap items-center gap-3">
                                        <h3 class="text-base font-semibold text-slate-900 dark:text-white mr-auto">Container logs</h3>
                                        <button type="button" @click="loadFullLogs()" :disabled="logsLoading" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg font-medium transition text-sm">
                                            <span x-text="logsLoading ? 'Loading…' : 'Refresh logs'"></span>
                                        </button>
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                                            <input type="checkbox" x-model="logsLive" @change="toggleLiveLogs()" class="rounded border-slate-300 dark:border-slate-600 text-blue-600">
                                            Live follow (2s)
                                        </label>
                                        <span class="text-sm text-slate-500 dark:text-slate-400">Last 200 lines · stdout/stderr</span>
                                        <span x-show="logsFetchedAt" class="text-xs font-mono text-slate-400" x-text="logsFetchedAt ? `Updated ${logsFetchedAt}` : ''"></span>
                                    </div>
                                    <pre class="bg-slate-900 text-slate-300 p-4 font-mono text-sm overflow-x-auto max-h-[32rem] whitespace-pre-wrap rounded-b-xl" x-ref="fullLogsEl" x-text="fullLogs || 'Loading logs…'"></pre>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- PHP Extensions Tab -->
                    @if (!empty($supportsPhpExtensions))
                        <template x-if="hasVisited('php-extensions')">
                            <div x-show="activeTab === 'php-extensions'">
                                @include('customer.services.partials.php-extensions')
                            </div>
                        </template>
                    @endif

                    <!-- Cron Jobs Tab -->
                    <template x-if="hasVisited('cron')">
                        <div x-show="activeTab === 'cron'">
                            @include('customer.services.partials.cron-jobs')
                        </div>
                    </template>

                    <!-- Documentation Tab (always mounted so ?tab=documentation deep-links work) -->
                    <div x-show="activeTab === 'documentation'" x-cloak class="space-y-0">
                        @include('customer.services.partials.documentation')
                    </div>
                </div>

                <!-- Mobile sticky actions -->
                <div class="md:hidden fixed bottom-0 inset-x-0 z-30 border-t border-slate-200 dark:border-slate-700 bg-white/95 dark:bg-slate-900/95 backdrop-blur px-4 py-3 flex gap-2 justify-center shadow-lg">
                    @if ($deployment->isRunning())
                        <form method="POST" action="{{ container_route('restart', $service) }}" data-confirm="Restart the app? There will be brief downtime." data-confirm-title="Restart app">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Restart</button>
                        </form>
                        @php
                            $mobileAccessUrl = $deployment->getAccessUrl();
                        @endphp
                        @if ($mobileAccessUrl)
                            <a
                                href="{{ $mobileAccessUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium"
                                onclick="window.open(this.href, '_blank'); return false;"
                            >Visit</a>
                        @endif
                    @elseif (in_array($deployment->status, ['stopped', 'failed']))
                        <form method="POST" action="{{ container_route('start', $service) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">Start</button>
                        </form>
                    @endif
                </div>
            </div>
        @else
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8 text-center">
                <p class="text-slate-600 dark:text-slate-400 text-lg">Application deployment in progress...</p>
            </div>
        @endif
