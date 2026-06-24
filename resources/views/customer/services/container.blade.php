@extends('layouts.customer')

@section('title', 'Container: ' . $service->name)

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
            @php
                $containerTabs = ['overview', 'files', 'terminal', 'backups', 'domains', 'database', 'logs', 'documentation'];
                if (! empty($supportsGitRepository)) {
                    array_splice($containerTabs, array_search('database', $containerTabs, true) + 1, 0, 'github');
                }
                $initialTab = in_array(request('tab'), $containerTabs, true) ? request('tab') : 'overview';
            @endphp
            <!-- Tab Navigation -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg mb-8 pb-20 md:pb-0" x-data="containerTabs(@js($initialTab))" x-init="init()" @container-set-tab.window="setTab($event.detail)">
                <div class="border-b border-slate-200 dark:border-slate-700 px-4 pt-4">
                    <label class="md:hidden block text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">Section</label>
                    <select :value="activeTab" @change="setTab($event.target.value)" class="md:hidden w-full mb-4 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm">
                        <option value="overview">Overview</option>
                        <option value="logs">Logs</option>
                        <option value="files">Files</option>
                        <option value="terminal">Terminal</option>
                        <option value="database">Database</option>
                        @if (!empty($supportsGitRepository))
                            <option value="github">GitHub</option>
                        @endif
                        <option value="domains">Domains</option>
                        <option value="backups">Backups</option>
                        <option value="documentation">Documentation</option>
                    </select>

                    <nav class="hidden md:flex flex-wrap gap-x-1" role="tablist">
                        @foreach ([['overview', 'Overview'], ['logs', 'Logs'], ['files', 'Files'], ['terminal', 'Terminal'], ['database', 'Database']] as [$tabKey, $tabLabel])
                            <button @click="setTab('{{ $tabKey }}')" :class="activeTab === '{{ $tabKey }}' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-3 font-medium transition text-sm" role="tab">{{ $tabLabel }}</button>
                        @endforeach
                        @if (!empty($supportsGitRepository))
                            <button @click="setTab('github')" :class="activeTab === 'github' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-3 font-medium transition text-sm" role="tab">GitHub</button>
                        @endif
                        @foreach ([['domains', 'Domains'], ['backups', 'Backups'], ['documentation', 'Docs']] as [$tabKey, $tabLabel])
                            <button @click="setTab('{{ $tabKey }}')" :class="activeTab === '{{ $tabKey }}' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-3 font-medium transition text-sm" role="tab">{{ $tabLabel }}</button>
                        @endforeach
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="p-8">
                    <!-- Overview Tab -->
                    <div x-show="activeTab === 'overview'" class="space-y-8">
                        <!-- Quick Actions -->
                        <div class="flex gap-3 flex-wrap items-center">
                            @if ($deployment->isRunning())
                                <form method="POST" action="{{ route('customer.services.container.stop', $service) }}" style="display:inline;" data-confirm="Stop this container? Your app will be unavailable until you start it again." data-confirm-title="Stop container">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium transition">
                                        Stop
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('customer.services.container.restart', $service) }}" style="display:inline;" data-confirm="Restart the container? There will be brief downtime." data-confirm-title="Restart container">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                        Restart
                                    </button>
                                </form>
                            @elseif (in_array($deployment->status, ['stopped', 'failed']))
                                <form method="POST" action="{{ route('customer.services.container.start', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        Start
                                    </button>
                                </form>
                            @endif

                            @if ($deployment->isRunning())
                                <a href="{{ $deployment->getAccessUrl() }}" target="_blank" rel="noopener" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition">
                                    Visit service
                                </a>
                            @else
                                <span class="px-5 py-2 bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 rounded-lg font-medium cursor-not-allowed" title="Start the container to visit your app">
                                    Visit service
                                </span>
                            @endif

                            <div class="relative" x-data="{ open: false }">
                                <button type="button" @click="open = !open" class="px-5 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg font-medium transition hover:bg-slate-200 dark:hover:bg-slate-600">
                                    Advanced ▾
                                </button>
                                <div x-show="open" @click.outside="open = false" x-cloak class="absolute left-0 mt-2 z-20 w-72 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 shadow-lg p-4 space-y-3">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Recreates the container runtime. Files in <code class="font-mono">/app</code> are kept unless you reset the database.</p>
                                    <form method="POST" action="{{ route('customer.services.container.redeploy', $service) }}" id="redeploy-form">
                                        @csrf
                                        <label class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300 mb-3">
                                            <input type="checkbox" name="reset_database" value="1" id="reset-database-checkbox" @checked(config('containers.redeploy.reset_database_default', false)) class="rounded border-slate-300 dark:border-slate-600 mt-0.5">
                                            <span>Reset database (deletes all DB data)</span>
                                        </label>
                                        <button
                                            type="button"
                                            class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition text-sm"
                                            onclick="confirmRedeploy(document.getElementById('redeploy-form'))"
                                        >
                                            Redeploy stack
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        @include('customer.services.partials.overview-quick-links')

                        @if (!empty($isLaravelTemplate))
                            @include('customer.services.partials.laravel-setup')
                        @endif

                        @include('customer.services.partials.enhanced-stats')
                    </div>

                    <!-- Files Tab -->
                    <template x-if="hasVisited('files')">
                        <div x-show="activeTab === 'files'">
                            @include('customer.services.partials.file-manager')
                        </div>
                    </template>

                    <!-- Terminal Tab -->
                    <template x-if="hasVisited('terminal')">
                        <div x-show="activeTab === 'terminal'">
                            @include('customer.services.partials.terminal')
                        </div>
                    </template>

                    <!-- Backups Tab -->
                    <template x-if="hasVisited('backups')">
                        <div x-show="activeTab === 'backups'">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Container Backups</h3>
                                <form method="POST" action="{{ route('customer.services.container.backups.create', $service) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                                        Create backup
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
                                                    <form method="POST" action="{{ route('customer.services.container.backups.restore', [$service, $backup]) }}" style="display:inline;" data-confirm="Restore this backup? Current container data will be replaced." data-confirm-title="Restore backup">
                                                        @csrf
                                                        <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                                            Restore
                                                        </button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('customer.services.container.backups.delete', [$service, $backup]) }}" style="display:inline;" data-confirm="Delete this backup permanently?" data-confirm-title="Delete backup">
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
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Custom Domains</h3>
                            </div>

                            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                <p class="text-sm text-blue-800 dark:text-blue-200">
                                    <strong>DNS setup:</strong> Point your domain's A record to
                                    <code class="font-mono">{{ $deployment->node->ip_address }}</code>
                                    before binding or updating a domain.
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
                                                            @if ($domain->ssl_enabled && $domain->status === 'active')
                                                                <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">🔒 SSL</span>
                                                            @endif
                                                            @if ($domain->error_message)
                                                                <span class="text-xs text-red-600 dark:text-red-400">{{ $domain->error_message }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <form x-show="editing" x-cloak method="POST" action="{{ route('customer.services.container.domains.update', [$service, $domain]) }}" class="flex flex-col sm:flex-row gap-2">
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
                                                    <button type="button" x-show="!editing" @click="editing = true" class="px-3 py-1 bg-slate-200 dark:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm rounded hover:bg-slate-300 dark:hover:bg-slate-500">
                                                        Edit
                                                    </button>
                                                    @if ($domain->status === 'active' && !$domain->ssl_enabled)
                                                        <form method="POST" action="{{ route('customer.services.container.domains.ssl', [$service, $domain]) }}" class="inline">
                                                            @csrf
                                                            <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                                                Get SSL
                                                            </button>
                                                        </form>
                                                    @endif
                                                    <form method="POST" action="{{ route('customer.services.container.domains.unbind', [$service, $domain]) }}" class="inline" onsubmit="return confirm('Remove {{ $domain->domain }} from this container? This also removes nginx routing and SSL for that hostname.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                                                            Remove
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-slate-600 dark:text-slate-400">No custom domains bound yet. Add one below after DNS is configured.</p>
                            @endif

                            <form method="POST" action="{{ route('customer.services.container.domains.bind', $service) }}" class="flex flex-col sm:flex-row gap-2">
                                @csrf
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
                                                    <button type="button" onclick="navigator.clipboard.writeText(@js($databaseContext['password']))" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                                        Copy
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                        <p class="font-mono text-slate-900 dark:text-white break-all">
                                            @if(!empty($databaseContext['password']))
                                                <span x-show="showDbPassword">{{ $databaseContext['password'] }}</span>
                                                <span x-show="!showDbPassword">{{ $databaseContext['password_masked'] }}</span>
                                            @else
                                                <span class="text-slate-500">Not available — redeploy to regenerate credentials.</span>
                                            @endif
                                        </p>
                                    </div>
                                    @if(!empty($databaseContext['connection']))
                                        <div class="bg-slate-50 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600 md:col-span-2">
                                            <p class="text-xs uppercase text-slate-500 dark:text-slate-400 mb-1">Connection (in container network)</p>
                                            <p class="font-mono text-sm text-slate-900 dark:text-white break-all">{{ $databaseContext['connection'] }}</p>
                                        </div>
                                    @endif
                                </div>

                                <p class="text-sm text-slate-600 dark:text-slate-400">
                                    Database credentials are provisioned automatically on deploy and redeploy. Use host <code class="font-mono">db</code> from your application container.
                                    For Laravel, tick <strong>Reset database</strong> on redeploy to wipe data and auto-update <code class="font-mono">/app/.env</code> plus migrations when the app is already installed.
                                </p>

                                <div class="p-4 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/50">
                                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-2">Import SQL dump</h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">
                                        Upload a <code class="font-mono">.sql</code> file to load tables and data into this service database (max {{ $dbImportMaxMb }} MB). Existing tables with the same names may be overwritten depending on your dump.
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
                                <div class="text-center py-12 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600 space-y-3">
                                    <p class="text-slate-600 dark:text-slate-400">No database sidecar is configured for this service.</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400 max-w-lg mx-auto">
                                        Redeploy to auto-provision MySQL for Laravel/PHP apps, or order a new container with a database selected during tech stack setup.
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
                                <div class="flex items-center gap-3">
                                    <button type="button" @click="loadFullLogs()" :disabled="logsLoading" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg font-medium transition">
                                        <span x-text="logsLoading ? 'Loading…' : 'Refresh logs'"></span>
                                    </button>
                                    <span class="text-sm text-slate-500 dark:text-slate-400">Container stdout/stderr</span>
                                </div>
                                <pre class="bg-slate-900 text-slate-300 p-4 rounded-lg font-mono text-sm overflow-x-auto max-h-[32rem] whitespace-pre-wrap" x-text="fullLogs || 'Loading logs…'"></pre>
                            </div>
                        </div>
                    </template>

                    <!-- Documentation Tab -->
                    <template x-if="hasVisited('documentation')">
                        <div x-show="activeTab === 'documentation'">
                            @include('customer.services.partials.documentation')
                        </div>
                    </template>
                </div>

                <!-- Mobile sticky actions -->
                <div class="md:hidden fixed bottom-0 inset-x-0 z-30 border-t border-slate-200 dark:border-slate-700 bg-white/95 dark:bg-slate-900/95 backdrop-blur px-4 py-3 flex gap-2 justify-center shadow-lg">
                    @if ($deployment->isRunning())
                        <form method="POST" action="{{ route('customer.services.container.restart', $service) }}" data-confirm="Restart the container? There will be brief downtime." data-confirm-title="Restart container">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Restart</button>
                        </form>
                        <a href="{{ $deployment->getAccessUrl() }}" target="_blank" rel="noopener" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Visit</a>
                    @elseif (in_array($deployment->status, ['stopped', 'failed']))
                        <form method="POST" action="{{ route('customer.services.container.start', $service) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">Start</button>
                        </form>
                    @endif
                </div>
            </div>
        @else
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8 text-center">
                <p class="text-slate-600 dark:text-slate-400 text-lg">Container deployment in progress...</p>
            </div>
        @endif

        @if (! in_array($service->status->value, ['terminated', 'cancelled']))
            <div
                class="mt-8 bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-red-200 dark:border-red-900/40 p-8"
                x-data="{ showDeleteModal: false, confirmName: '' }"
            >
                <h2 class="text-lg font-semibold text-red-700 dark:text-red-400">Danger Zone</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-2xl">
                    Permanently delete this service and shut down its container. All data will be removed and this cannot be undone.
                </p>
                <button
                    type="button"
                    @click="showDeleteModal = true; confirmName = ''"
                    class="mt-4 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm"
                >
                    Delete Service
                </button>

                <div x-show="showDeleteModal" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full p-6" @click.stop>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Delete Service</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                            This will terminate the container and remove the service from your account. Type
                            <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $service->name }}</span>
                            to confirm.
                        </p>

                        <form method="POST" action="{{ route('customer.services.container.destroy', $service) }}">
                            @csrf
                            @method('DELETE')
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Service name</label>
                            <input
                                type="text"
                                name="service_name"
                                x-model="confirmName"
                                autocomplete="off"
                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 rounded-lg text-sm focus:ring-2 focus:ring-red-500 dark:focus:ring-red-400 mb-4"
                                placeholder="Type service name exactly"
                            >

                            <div class="flex gap-3">
                                <button
                                    type="button"
                                    @click="showDeleteModal = false"
                                    class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-600 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="confirmName !== @js($service->name)"
                                    class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition"
                                >
                                    Delete Service
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
function containerTabs(initialTab) {
    return {
        activeTab: initialTab,
        visitedTabs: [initialTab],
        fullLogs: '',
        logsLoading: false,

        init() {
            if (this.activeTab === 'logs') {
                this.loadFullLogs();
            }
        },

        setTab(tab) {
            if (!this.visitedTabs.includes(tab)) {
                this.visitedTabs.push(tab);
            }
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            history.replaceState({}, '', url);
            if (tab === 'logs') {
                this.$nextTick(() => this.loadFullLogs());
            }
        },

        hasVisited(tab) {
            return this.visitedTabs.includes(tab);
        },

        async loadFullLogs() {
            this.logsLoading = true;
            this.fullLogs = 'Loading logs…';

            try {
                const response = await fetch('{{ route("customer.services.container.logs", $service) }}');
                const data = await response.json();

                if (data.error) {
                    this.fullLogs = `Error: ${data.error}`;
                } else {
                    this.fullLogs = data.logs || 'No logs available';
                }
            } catch (error) {
                this.fullLogs = 'Failed to fetch logs';
                console.error('Error:', error);
            } finally {
                this.logsLoading = false;
            }
        },
    };
}

async function confirmRedeploy(form) {
    const resetDb = form.querySelector('input[name="reset_database"]')?.checked;
    let message = 'Redeploy stack now? This recreates the container runtime and keeps /app files.';
    if (resetDb) {
        message += '\n\nThe database volume will be wiped (all tables and data deleted).';
        message += '\nIf Laravel is installed, /app/.env will be refreshed and migrations will run.';
    } else {
        message += '\n\nDatabase data is kept unless you tick Reset database.';
    }

    const accepted = await window.appConfirm(message, 'Redeploy stack', 'Redeploy');
    if (accepted) {
        form.submit();
    }
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

async function importDatabaseSql() {
    const fileInput = document.getElementById('db-import-file');
    const statusEl = document.getElementById('db-import-status');
    const outEl = document.getElementById('db-import-output');
    if (!fileInput || !statusEl) return;

    const file = fileInput.files?.[0];
    if (!file) {
        statusEl.textContent = 'Choose a .sql file first.';
        return;
    }

    const maxBytes = {{ $dbImportMaxMb }} * 1024 * 1024;
    if (file.size > maxBytes) {
        statusEl.textContent = `File exceeds {{ $dbImportMaxMb }} MB limit.`;
        return;
    }

    if (!(await window.appConfirm('Import this SQL file into your service database? This may change or overwrite existing data.', 'Import SQL', 'Import'))) {
        return;
    }

    statusEl.textContent = 'Importing...';
    if (outEl) {
        outEl.classList.add('hidden');
        outEl.textContent = '';
    }

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await fetch('{{ route("customer.services.container.database.import", $service) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: formData,
        });

        const data = await response.json();
        if (!response.ok) {
            statusEl.textContent = 'Import failed';
            if (outEl) {
                outEl.classList.remove('hidden');
                outEl.textContent = data.error || 'Import failed';
            }
            return;
        }

        statusEl.textContent = 'Import complete';
        if (outEl) {
            outEl.classList.remove('hidden');
            outEl.textContent = data.output || data.message || 'Done';
        }
        fileInput.value = '';
        loadDatabaseHistory();
    } catch (error) {
        statusEl.textContent = 'Import failed';
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
            const label = row.action === 'db_import' ? 'Import' : 'Query';
            return `<div class="p-3 text-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-slate-500 dark:text-slate-400">${row.at || ''} · ${label}</span>
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
