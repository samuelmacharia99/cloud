@extends('layouts.app')

@section('content')
<div class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800 min-h-screen py-8">
    <div class="max-w-7xl mx-auto px-4">
        <!-- Header -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8 mb-8">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-4">
                        <div>
                            <h1 class="text-4xl font-bold text-slate-900 dark:text-white">{{ $service->name }}</h1>
                            <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $service->product->containerTemplate->name ?? 'Container Service' }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @php
                        $statusConfig = match($deployment?->status) {
                            'running'   => ['pulse' => 'bg-green-400',  'ring' => 'bg-green-500',  'text' => 'Running',   'textClass' => 'text-green-700 dark:text-green-300',  'bg' => 'bg-green-50 dark:bg-green-900/30',  'border' => 'border-green-200 dark:border-green-700'],
                            'stopped'   => ['pulse' => null,             'ring' => 'bg-yellow-400', 'text' => 'Stopped',   'textClass' => 'text-yellow-700 dark:text-yellow-300', 'bg' => 'bg-yellow-50 dark:bg-yellow-900/30', 'border' => 'border-yellow-200 dark:border-yellow-700'],
                            'deploying' => ['pulse' => 'bg-blue-400',   'ring' => 'bg-blue-500',   'text' => 'Deploying', 'textClass' => 'text-blue-700 dark:text-blue-300',    'bg' => 'bg-blue-50 dark:bg-blue-900/30',    'border' => 'border-blue-200 dark:border-blue-700'],
                            'failed'    => ['pulse' => null,             'ring' => 'bg-red-500',    'text' => 'Failed',    'textClass' => 'text-red-700 dark:text-red-300',      'bg' => 'bg-red-50 dark:bg-red-900/30',      'border' => 'border-red-200 dark:border-red-700'],
                            default     => ['pulse' => null,             'ring' => 'bg-slate-400',  'text' => 'Pending',   'textClass' => 'text-slate-700 dark:text-slate-300',  'bg' => 'bg-slate-50 dark:bg-slate-800',     'border' => 'border-slate-200 dark:border-slate-700'],
                        };
                    @endphp
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold {{ $statusConfig['bg'] }} {{ $statusConfig['border'] }} border {{ $statusConfig['textClass'] }}">
                        <span class="relative flex h-2 w-2">
                            @if($statusConfig['pulse'])
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $statusConfig['pulse'] }} opacity-75"></span>
                            @endif
                            <span class="relative inline-flex rounded-full h-2 w-2 {{ $statusConfig['ring'] }}"></span>
                        </span>
                        {{ $statusConfig['text'] }}
                    </span>
                    <a href="{{ route('customer.services.index') }}" class="px-4 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition">
                        ← Services
                    </a>
                </div>
            </div>

            <!-- Quick Stats Row -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-8 pt-8 border-t border-slate-200 dark:border-slate-700">
                <div class="text-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">CPU</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $service->product->containerTemplate->required_cpu_cores }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-500">cores</p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Memory</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $service->product->containerTemplate->required_ram_mb }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-500">MB</p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Storage</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $service->product->containerTemplate->required_storage_gb }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-500">GB</p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Uptime</p>
                    @if ($deployment?->deployed_at && $deployment->status === 'running')
                        <p id="uptime-counter" class="text-2xl font-bold text-slate-900 dark:text-white">—</p>
                        <p class="text-xs text-slate-500 dark:text-slate-500">live</p>
                        <script>
                            (function() {
                                const deployedAt = {{ $deployment->deployed_at->timestamp }};
                                const el = document.getElementById('uptime-counter');
                                function update() {
                                    const s = Math.floor(Date.now() / 1000) - deployedAt;
                                    const d = Math.floor(s / 86400);
                                    const h = Math.floor((s % 86400) / 3600);
                                    const m = Math.floor((s % 3600) / 60);
                                    el.textContent = d > 0 ? `${d}d ${h}h` : (h > 0 ? `${h}h ${m}m` : `${m}m`);
                                }
                                update();
                                setInterval(update, 30000);
                            })();
                        </script>
                    @elseif ($deployment?->deployed_at)
                        <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $deployment->deployed_at->diffForHumans(null, true, true) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-500">since deploy</p>
                    @else
                        <p class="text-sm text-slate-500">Not deployed</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Alerts -->
        @if ($message = Session::get('success'))
            <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-lg">
                {{ $message }}
            </div>
        @endif

        @if ($message = Session::get('error'))
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg">
                {{ $message }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if ($deployment)
            <!-- Tab Navigation -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg mb-8" x-data="{ activeTab: 'overview' }">
                <div class="border-b border-slate-200 dark:border-slate-700">
                    <nav class="flex" role="tablist">
                        <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">📊 Overview</button>
                        <button @click="activeTab = 'files'" :class="activeTab === 'files' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">📁 Files</button>
                        <button @click="activeTab = 'terminal'" :class="activeTab === 'terminal' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">⌨️ Terminal</button>
                        <button @click="activeTab = 'backups'" :class="activeTab === 'backups' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">💾 Backups</button>
                        <button @click="activeTab = 'domains'" :class="activeTab === 'domains' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">🌐 Domains</button>
                        <button @click="activeTab = 'database'" :class="activeTab === 'database' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">🗄️ Database</button>
                        <button @click="activeTab = 'logs'" :class="activeTab === 'logs' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-6 py-4 font-medium transition" role="tab">📋 Logs</button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="p-8">
                    <!-- Overview Tab -->
                    <div x-show="activeTab === 'overview'" class="space-y-8">
                        <!-- Quick Actions -->
                        <div class="flex gap-3 flex-wrap">
                            @if ($deployment->isRunning())
                                <form method="POST" action="{{ route('customer.services.container.stop', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-6 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium transition">
                                        ⏹️ Stop
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('customer.services.container.restart', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                        🔄 Restart
                                    </button>
                                </form>
                            @elseif ($deployment->status === 'stopped')
                                <form method="POST" action="{{ route('customer.services.container.start', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        ▶️ Start
                                    </button>
                                </form>
                            @endif

                            <a href="{{ $deployment->getAccessUrl() }}" target="_blank" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition">
                                🔗 Visit Service
                            </a>

                            <form method="POST" action="{{ route('customer.services.container.redeploy', $service) }}" style="display:inline;">
                                @csrf
                                <button
                                    type="submit"
                                    class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition"
                                    onclick="return confirm('Redeploy stack now? This can briefly interrupt service while containers are recreated.')"
                                >
                                    ♻️ Redeploy Stack
                                </button>
                            </form>
                        </div>

                        <!-- Stats Dashboard -->
                        @include('customer.services.partials.enhanced-stats')
                    </div>

                    <!-- Files Tab -->
                    <div x-show="activeTab === 'files'">
                        @include('customer.services.partials.file-manager')
                    </div>

                    <!-- Terminal Tab -->
                    <div x-show="activeTab === 'terminal'">
                        @include('customer.services.partials.terminal')
                    </div>

                    <!-- Backups Tab -->
                    <div x-show="activeTab === 'backups'">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Container Backups</h3>
                                <form method="POST" action="{{ route('customer.services.container.backups.create', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        ✨ Create Backup
                                    </button>
                                </form>
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
                                                    @else
                                                        Status: {{ ucfirst($backup->status) }}
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="flex gap-2">
                                                @if ($backup->status === 'completed')
                                                    <form method="POST" action="{{ route('customer.services.container.backups.restore', [$service, $backup]) }}" style="display:inline;">
                                                        @csrf
                                                        <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700" onclick="return confirm('Restore from this backup?')">
                                                            Restore
                                                        </button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('customer.services.container.backups.delete', [$service, $backup]) }}" style="display:inline;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700" onclick="return confirm('Delete this backup?')">
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

                    <!-- Domains Tab -->
                    <div x-show="activeTab === 'domains'">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Custom Domains</h3>
                            </div>

                            @if ($deployment->domains()->count() > 0)
                                <div class="space-y-3">
                                    @foreach ($deployment->domains as $domain)
                                        <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                            <div>
                                                <p class="font-mono font-semibold text-slate-900 dark:text-white">{{ $domain->domain }}</p>
                                                <div class="flex items-center gap-2 mt-2">
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
                                                    @if ($domain->ssl_enabled && $domain->status === 'active')
                                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">🔒 SSL</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                @if ($domain->status === 'active' && !$domain->ssl_enabled)
                                                    <form method="POST" action="{{ route('customer.services.container.domains.ssl', [$service, $domain]) }}" style="display:inline;">
                                                        @csrf
                                                        <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                                            Enable SSL
                                                        </button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('customer.services.container.domains.unbind', [$service, $domain]) }}" style="display:inline;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700" onclick="return confirm('Remove this domain?')">
                                                        Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                                    <p class="text-sm text-blue-800 dark:text-blue-200"><strong>DNS Setup:</strong> Point your domain's A record to <code class="font-mono">{{ $deployment->node->ip_address }}</code></p>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('customer.services.container.domains.bind', $service) }}" class="flex gap-2">
                                @csrf
                                <input type="text" name="domain" placeholder="example.com" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg dark:bg-slate-700 dark:text-white" required>
                                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                    Add Domain
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Database Tab -->
                    <div x-show="activeTab === 'database'">
                        <div class="space-y-6">
                            @if(empty($databaseConsoleEnabled))
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600">
                                    <p class="text-slate-600 dark:text-slate-400">Database console is disabled by administrator.</p>
                                </div>
                            @elseif(!empty($databaseContext['available']))
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                                </div>

                                <div class="p-4 rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700">
                                    <p class="text-sm text-amber-900 dark:text-amber-200">
                                        Read-only SQL console: only <code>SELECT</code>, <code>SHOW</code>, <code>DESCRIBE</code>, and <code>EXPLAIN</code> are allowed.
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
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600">
                                    <p class="text-slate-600 dark:text-slate-400">No database sidecar is configured for this service.</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Logs Tab -->
                    <div x-show="activeTab === 'logs'">
                        <div class="space-y-4">
                            <button @click="fetchLogs()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                📜 Load Logs
                            </button>
                            <div id="logs-content" class="bg-slate-900 text-slate-300 p-4 rounded-lg font-mono text-sm overflow-x-auto max-h-96">
                                <p class="text-slate-500">Click "Load Logs" to fetch container logs</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8 text-center">
                <p class="text-slate-600 dark:text-slate-400 text-lg">Container deployment in progress...</p>
            </div>
        @endif
    </div>
</div>

<script>
function fetchLogs() {
    const logsContent = document.getElementById('logs-content');
    logsContent.innerHTML = '<p class="text-slate-500">Loading logs...</p>';

    fetch('{{ route("customer.services.container.logs", $service) }}')
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                logsContent.innerHTML = '<p class="text-red-400">Error: ' + data.error + '</p>';
            } else {
                logsContent.textContent = data.logs || 'No logs available';
            }
        })
        .catch(error => {
            logsContent.innerHTML = '<p class="text-red-400">Failed to fetch logs</p>';
            console.error('Error:', error);
        });
}

async function runDatabaseQuery(format = 'text') {
    const queryEl = document.getElementById('db-query');
    const outEl = document.getElementById('db-query-output');
    const statusEl = document.getElementById('db-query-status');
    if (!queryEl || !outEl || !statusEl) return;

    const query = queryEl.value.trim();
    if (!query) {
        statusEl.textContent = 'Enter a query first.';
        return;
    }

    statusEl.textContent = 'Running...';
    outEl.textContent = 'Executing query...';

    try {
        const response = await fetch('{{ route("customer.services.container.database.query", $service) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ query, format })
        });

        const data = await response.json();
        if (!response.ok) {
            statusEl.textContent = 'Failed';
            outEl.textContent = data.error || 'Query failed';
            return;
        }

        statusEl.textContent = 'Done';
        outEl.textContent = data.output || '(empty result)';
        if (format === 'csv' && data.csv) {
            const blob = new Blob([data.csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'query-result.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }
        loadDatabaseHistory();
    } catch (error) {
        statusEl.textContent = 'Failed';
        outEl.textContent = 'Request failed';
    }
}

async function loadDatabaseHistory() {
    const historyEl = document.getElementById('db-query-history');
    if (!historyEl) return;

    historyEl.innerHTML = '<div class="p-3 text-sm text-slate-500 dark:text-slate-400">Loading history...</div>';
    try {
        const response = await fetch('{{ route("customer.services.container.database.history", $service) }}', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (!response.ok) {
            historyEl.innerHTML = `<div class="p-3 text-sm text-red-600">${data.error || 'Failed to load history'}</div>`;
            return;
        }

        const rows = data.history || [];
        if (!rows.length) {
            historyEl.innerHTML = '<div class="p-3 text-sm text-slate-500 dark:text-slate-400">No query history yet.</div>';
            return;
        }

        historyEl.innerHTML = rows.map((row) => {
            const stateClass = row.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
            const stateText = row.success ? 'OK' : 'Failed';
            return `<div class="p-3 text-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-slate-500 dark:text-slate-400">${row.at || ''}</span>
                    <span class="${stateClass} font-semibold">${stateText}</span>
                </div>
                <div class="text-slate-700 dark:text-slate-300 font-mono break-all">${row.query || ''}</div>
            </div>`;
        }).join('');
    } catch (error) {
        historyEl.innerHTML = '<div class="p-3 text-sm text-red-600">Failed to load history</div>';
    }
}
</script>
@endsection
