@extends('layouts.admin')

@section('title', 'Node: ' . $node->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $node->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $node->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $node->hostname }} ({{ $node->ip_address }})</p>
        </div>
        <div class="flex gap-2">
            @if(in_array($node->type, ['container_host', 'database_server', 'directadmin']))
                <form method="POST" action="{{ route('admin.nodes.test-health', $node) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Test Health
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.nodes.edit', $node) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                Edit Node
            </a>
            <a href="{{ route('admin.nodes.delete-confirm', $node) }}" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                Delete
            </a>
        </div>
    </div>

    <!-- Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Type</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $node->getTypeLabel() }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Status</p>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full
                    @if($node->status === 'online')
                        bg-emerald-500
                    @elseif($node->status === 'offline')
                        bg-red-500
                    @elseif($node->status === 'degraded')
                        bg-amber-500
                    @elseif($node->status === 'maintenance')
                        bg-blue-500
                    @endif
                "></span>
                <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ ucfirst($node->status) }}</p>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Active Services</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $nodeServices->total() }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Last Heartbeat</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $node->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</p>
        </div>
    </div>

    <!-- Utilization (not for DirectAdmin control panel servers) -->
    @if($node->type !== 'directadmin')
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Resource Utilization</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- CPU -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-slate-900 dark:text-white">CPU Usage</label>
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $cpuPercentage }}%</span>
                </div>
                <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-3">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full" style="width: {{ min($cpuPercentage, 100) }}%"></div>
                </div>
                <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">
                    {{ $node->cpu_used }}% / {{ $node->cpu_cores }} cores
                </p>
            </div>

            <!-- RAM -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-slate-900 dark:text-white">RAM Usage</label>
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $ramPercentage }}%</span>
                </div>
                <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-3">
                    <div class="bg-gradient-to-r from-amber-500 to-amber-600 h-3 rounded-full" style="width: {{ min($ramPercentage, 100) }}%"></div>
                </div>
                <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">
                    {{ $node->ram_used_gb }} GB / {{ $node->ram_gb }} GB
                </p>
            </div>

            <!-- Storage -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-slate-900 dark:text-white">Storage Usage</label>
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $storagePercentage }}%</span>
                </div>
                <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-3">
                    <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 h-3 rounded-full" style="width: {{ min($storagePercentage, 100) }}%"></div>
                </div>
                <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">
                    {{ $node->storage_used_gb }} GB / {{ $node->storage_gb }} GB
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Monitoring Dashboard (for container hosts and database servers) -->
    @if($node->isMonitored())
        @php
            $monitoringAlert = $node->latestMonitoring?->getAlert();
            $monitoringExpandedDefault = filled($monitoringAlert) ? 'true' : 'false';
        @endphp
        <div
            x-data="{ expanded: {{ $monitoringExpandedDefault }} }"
            class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden"
        >
            <button
                type="button"
                @click="expanded = !expanded"
                class="w-full p-8 text-left flex items-center justify-between gap-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">24h Monitoring</h2>
                        @if($monitoringAlert)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300">
                                ⚠️ {{ $monitoringAlert }}
                            </span>
                        @endif
                    </div>
                    @if($node->latestMonitoring)
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            Uptime <strong class="text-slate-900 dark:text-white">{{ $node->latestMonitoring->uptime_percentage }}%</strong>
                            · RAM <strong class="text-slate-900 dark:text-white">{{ $node->latestMonitoring->getRamUsagePercentage() }}%</strong>
                            · Storage <strong class="text-slate-900 dark:text-white">{{ $node->latestMonitoring->getStorageUsagePercentage() }}%</strong>
                            @if($node->latestMonitoring->recorded_at)
                                · Updated {{ $node->latestMonitoring->recorded_at->diffForHumans() }}
                            @endif
                        </p>
                    @else
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">No monitoring data received yet — waiting for first heartbeat.</p>
                    @endif
                </div>
                <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400 shrink-0">
                    <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                    <svg class="w-5 h-5 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </button>

            <div x-show="expanded" x-cloak class="px-8 pb-8 border-t border-slate-200 dark:border-slate-800 pt-6">
            @if($node->latestMonitoring)
                <!-- Real-time Gauges -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Uptime Gauge -->
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-6">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-4">Uptime</p>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ $node->latestMonitoring->uptime_percentage }}%</span>
                            <span class="
                                @if($node->latestMonitoring->uptime_percentage >= 95)
                                    text-emerald-500
                                @elseif($node->latestMonitoring->uptime_percentage >= 90)
                                    text-amber-500
                                @else
                                    text-red-500
                                @endif
                            ">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12z"/></svg>
                            </span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                            <div class="h-2 rounded-full
                                @if($node->latestMonitoring->uptime_percentage >= 95)
                                    bg-emerald-500
                                @elseif($node->latestMonitoring->uptime_percentage >= 90)
                                    bg-amber-500
                                @else
                                    bg-red-500
                                @endif
                            " style="width: {{ $node->latestMonitoring->uptime_percentage }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Last 24 hours</p>
                    </div>

                    <!-- RAM Gauge -->
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-6">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-4">RAM Usage</p>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ $node->latestMonitoring->getRamUsagePercentage() }}%</span>
                            <span class="
                                @if($node->latestMonitoring->getRamUsagePercentage() <= 85)
                                    text-emerald-500
                                @elseif($node->latestMonitoring->getRamUsagePercentage() <= 90)
                                    text-amber-500
                                @else
                                    text-red-500
                                @endif
                            ">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12z"/></svg>
                            </span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                            <div class="h-2 rounded-full
                                @if($node->latestMonitoring->getRamUsagePercentage() <= 85)
                                    bg-emerald-500
                                @elseif($node->latestMonitoring->getRamUsagePercentage() <= 90)
                                    bg-amber-500
                                @else
                                    bg-red-500
                                @endif
                            " style="width: {{ $node->latestMonitoring->getRamUsagePercentage() }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $node->latestMonitoring->ram_used_gb }} / {{ $node->latestMonitoring->ram_total_gb }} GB</p>
                    </div>

                    <!-- Storage Gauge -->
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-6">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-4">Storage Usage</p>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-3xl font-bold text-slate-900 dark:text-white">{{ $node->latestMonitoring->getStorageUsagePercentage() }}%</span>
                            <span class="
                                @if($node->latestMonitoring->getStorageUsagePercentage() <= 90)
                                    text-emerald-500
                                @elseif($node->latestMonitoring->getStorageUsagePercentage() <= 95)
                                    text-amber-500
                                @else
                                    text-red-500
                                @endif
                            ">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12z"/></svg>
                            </span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                            <div class="h-2 rounded-full
                                @if($node->latestMonitoring->getStorageUsagePercentage() <= 90)
                                    bg-emerald-500
                                @elseif($node->latestMonitoring->getStorageUsagePercentage() <= 95)
                                    bg-amber-500
                                @else
                                    bg-red-500
                                @endif
                            " style="width: {{ $node->latestMonitoring->getStorageUsagePercentage() }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $node->latestMonitoring->storage_used_gb }} / {{ $node->latestMonitoring->storage_total_gb }} GB</p>
                    </div>
                </div>

                <!-- Monitoring History -->
                <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Recent Readings (Last 24h)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-2 font-semibold text-slate-600 dark:text-slate-400">Time</th>
                                    <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Uptime</th>
                                    <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">RAM</th>
                                    <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Storage</th>
                                    <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @forelse($node->monitoring()->latest('recorded_at')->limit(20)->get() as $reading)
                                    <tr>
                                        <td class="py-3 text-slate-600 dark:text-slate-400">
                                            {{ $reading->recorded_at->format('M d, H:i') }}
                                        </td>
                                        <td class="py-3 text-right">
                                            <span class="
                                                @if($reading->uptime_percentage >= 95)
                                                    text-emerald-600 dark:text-emerald-400
                                                @elseif($reading->uptime_percentage >= 90)
                                                    text-amber-600 dark:text-amber-400
                                                @else
                                                    text-red-600 dark:text-red-400
                                                @endif
                                            ">
                                                {{ $reading->uptime_percentage }}%
                                            </span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <span class="
                                                @if($reading->getRamUsagePercentage() <= 85)
                                                    text-emerald-600 dark:text-emerald-400
                                                @elseif($reading->getRamUsagePercentage() <= 90)
                                                    text-amber-600 dark:text-amber-400
                                                @else
                                                    text-red-600 dark:text-red-400
                                                @endif
                                            ">
                                                {{ $reading->getRamUsagePercentage() }}%
                                            </span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <span class="
                                                @if($reading->getStorageUsagePercentage() <= 90)
                                                    text-emerald-600 dark:text-emerald-400
                                                @elseif($reading->getStorageUsagePercentage() <= 95)
                                                    text-amber-600 dark:text-amber-400
                                                @else
                                                    text-red-600 dark:text-red-400
                                                @endif
                                            ">
                                                {{ $reading->getStorageUsagePercentage() }}%
                                            </span>
                                        </td>
                                        <td class="py-3 text-right">
                                            @if($reading->isHealthy())
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                                    Healthy
                                                </span>
                                            @elseif($reading->isDegraded())
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                                                    Warning
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-slate-500 dark:text-slate-400">
                                            No monitoring data yet. Nodes will send data via heartbeat.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="text-center py-8 text-slate-500 dark:text-slate-400">
                    <p>No monitoring data received yet.</p>
                    <p class="text-sm mt-1">Waiting for first heartbeat from node...</p>
                </div>
            @endif
            </div>
        </div>
    @endif

    <!-- DirectAdmin Packages (DA nodes only) -->
    @if($node->type === 'directadmin')
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Domain Nameservers</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div><dt class="text-slate-500 dark:text-slate-400">NS1</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $node->nameserver_1 ?: '—' }}</dd></div>
                <div><dt class="text-slate-500 dark:text-slate-400">NS2</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $node->nameserver_2 ?: '—' }}</dd></div>
                <div><dt class="text-slate-500 dark:text-slate-400">NS3</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $node->nameserver_3 ?: '—' }}</dd></div>
                <div><dt class="text-slate-500 dark:text-slate-400">NS4</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $node->nameserver_4 ?: '—' }}</dd></div>
            </dl>
        </div>

        @if(!$node->da_login_key)
            <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl p-4 mb-6">
                <p class="text-sm font-semibold text-red-800 dark:text-red-200 mb-2">⚠ DirectAdmin Login Key Not Set</p>
                <p class="text-sm text-red-700 dark:text-red-300">The login key is required to sync packages and test the connection. <a href="{{ route('admin.nodes.edit', $node) }}" class="font-medium underline hover:no-underline">Edit this node</a> and add your DirectAdmin login key.</p>
            </div>
        @endif

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
            <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin User Packages</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        End-user hosting plans synced from this server
                        @if($packages->isNotEmpty())
                            &middot; {{ $packages->count() }} {{ \Illuminate\Support\Str::plural('package', $packages->count()) }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    @if($node->status !== 'online')
                        <form method="POST" action="{{ route('admin.nodes.test-connection', $node) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Test Connection
                            </button>
                        </form>
                    @endif
                    @if($directAdminPeerCount > 0)
                        <a href="{{ route('admin.shared-hosting.package-consistency') }}"
                           class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                            </svg>
                            Compare across {{ $directAdminPeerCount + 1 }} DA nodes
                        </a>
                    @endif
                    <form method="POST" action="{{ route('admin.nodes.sync-packages', $node) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Sync Now
                        </button>
                    </form>
                </div>
            </div>

            @if($packages->isEmpty())
                <div class="text-center py-12 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                    <svg class="w-12 h-12 text-slate-400 dark:text-slate-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <p class="text-slate-700 dark:text-slate-300 font-medium">No packages synced from this server yet.</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Click <span class="font-semibold">Sync Now</span> to pull packages from the DirectAdmin API.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">Package</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Disk</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Bandwidth</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Domains</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">FTP</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Email</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">DBs</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Subdomains</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Last Sync</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Status</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($packages as $package)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="py-3 px-3">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $package->name }}</p>
                                        <code class="text-xs text-slate-500 dark:text-slate-400 font-mono">{{ $package->package_key }}</code>
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->disk_quota < 0 ? '∞' : (rtrim(rtrim(number_format((float) $package->disk_quota, 2), '0'), '.') . ' GB') }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        @if($package->bandwidth_quota === null)
                                            <span class="text-slate-400">—</span>
                                        @else
                                            {{ $package->bandwidth_quota < 0 ? '∞' : (rtrim(rtrim(number_format((float) $package->bandwidth_quota, 2), '0'), '.') . ' GB') }}
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->num_domains < 0 ? '∞' : $package->num_domains }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->num_ftp < 0 ? '∞' : $package->num_ftp }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->num_email_accounts < 0 ? '∞' : $package->num_email_accounts }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->num_databases < 0 ? '∞' : $package->num_databases }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $package->num_subdomains < 0 ? '∞' : $package->num_subdomains }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-500 dark:text-slate-400 text-xs">
                                        {{ $package->updated_at?->diffForHumans() ?? '—' }}
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        @if($package->is_active)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">Active</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <form method="POST" action="{{ route('admin.direct-admin-packages.push-limits', $package) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" title="Push local catalog limits to DirectAdmin">
                                                Push limits
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">
                    <span class="font-medium">Sync Now</span> pulls limits from DirectAdmin into this catalog.
                    <span class="font-medium">Push limits</span> writes the local catalog back to DirectAdmin (also runs automatically before new account provisioning).
                    Use
                    <a href="{{ route('admin.shared-hosting.package-consistency') }}" class="text-blue-600 dark:text-blue-400 hover:underline">Compare across DA nodes</a>
                    to spot drift between servers.
                </p>
            @endif
        </div>

        {{-- Admin reseller packages (live from DirectAdmin) --}}
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 mb-6">
            <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin Reseller Packages</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Reseller account limits defined on this server
                        @if(!empty($resellerPackages))
                            &middot; {{ count($resellerPackages) }} {{ \Illuminate\Support\Str::plural('package', count($resellerPackages)) }}
                        @endif
                        &middot; Live from API (cached 5 min)
                    </p>
                </div>
                <a href="{{ route('admin.nodes.show', ['node' => $node, 'refresh_reseller_packages' => 1]) }}"
                   class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Refresh
                </a>
            </div>

            @if($resellerPackagesError)
                <div class="rounded-xl border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/30 p-4 text-sm text-amber-800 dark:text-amber-200">
                    {{ $resellerPackagesError }}
                </div>
            @elseif(empty($resellerPackages))
                <div class="text-center py-10 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                    <p class="text-slate-700 dark:text-slate-300 font-medium">No reseller packages found on this server.</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Create reseller packages in the DirectAdmin admin panel, then click Refresh.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">Package</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Disk</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Bandwidth</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Domains</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">IPs</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Email</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">DBs</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Features</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($resellerPackages as $package)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="py-3 px-3">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $package['name'] }}</p>
                                        <code class="text-xs text-slate-500 dark:text-slate-400 font-mono">{{ $package['package_key'] }}</code>
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['disk_quota'] ?? 0) < 0 ? '∞' : (rtrim(rtrim(number_format((float) ($package['disk_quota'] ?? 0), 2), '0'), '.') . ' GB') }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['bandwidth_quota'] ?? 0) < 0 ? '∞' : (rtrim(rtrim(number_format((float) ($package['bandwidth_quota'] ?? 0), 2), '0'), '.') . ' GB') }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['num_domains'] ?? 0) < 0 ? '∞' : ($package['num_domains'] ?? 0) }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['num_ips'] ?? 0) < 0 ? '∞' : ($package['num_ips'] ?? 0) }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['num_email_accounts'] ?? 0) < 0 ? '∞' : ($package['num_email_accounts'] ?? 0) }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ ($package['num_databases'] ?? 0) < 0 ? '∞' : ($package['num_databases'] ?? 0) }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-600 dark:text-slate-400 text-xs">
                                        @php $features = $package['features'] ?? []; @endphp
                                        @if(!empty($features['ssl'])) SSL @endif
                                        @if(!empty($features['ssh'])) SSH @endif
                                        @if(!empty($features['dnscontrol'])) DNS @endif
                                        @if(!empty($features['serverip'])) Server IP @endif
                                        @if(empty(array_filter($features ?? []))) — @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Platform resellers linked to this node --}}
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 mb-6">
            <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Resellers on This Node</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Platform resellers assigned to this server or with hosting here
                        @if($nodeResellers->isNotEmpty())
                            &middot; {{ $nodeResellers->count() }} {{ \Illuminate\Support\Str::plural('reseller', $nodeResellers->count()) }}
                        @endif
                        &middot; DA user counts cached 5 min
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.nodes.show', ['node' => $node, 'refresh_resellers' => 1]) }}"
                       class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh counts
                    </a>
                    <a href="{{ route('admin.resellers.index') }}"
                       class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                        All resellers
                    </a>
                </div>
            </div>

            @if($nodeResellers->isEmpty())
                <div class="text-center py-10 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                    <p class="text-slate-700 dark:text-slate-300 font-medium">No resellers linked to this node yet.</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Assign a DirectAdmin node on each reseller's admin profile under <span class="font-medium">DirectAdmin username &amp; node</span>.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">Reseller</th>
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">DirectAdmin</th>
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">Platform package</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Portal services</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">DA users</th>
                                <th class="text-left py-3 px-3 font-semibold text-slate-900 dark:text-white">Binding</th>
                                <th class="text-right py-3 px-3 font-semibold text-slate-900 dark:text-white">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($nodeResellers as $reseller)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="py-3 px-3">
                                        <a href="{{ route('admin.resellers.show', $reseller) }}" class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                            {{ $reseller->name }}
                                        </a>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $reseller->email }}</p>
                                    </td>
                                    <td class="py-3 px-3">
                                        @if($reseller->directadmin_username)
                                            <code class="text-xs font-mono text-slate-800 dark:text-slate-200">{{ $reseller->directadmin_username }}</code>
                                        @else
                                            <span class="text-slate-400">Not set</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-slate-700 dark:text-slate-300">
                                        {{ $reseller->resellerPackage?->name ?? '—' }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        {{ $reseller->node_services_count ?? 0 }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-slate-700 dark:text-slate-300">
                                        @if($reseller->da_hosted_users_count !== null)
                                            <span class="font-medium">{{ $reseller->da_hosted_users_count }}</span>
                                            @if($reseller->resellerPackage?->max_users)
                                                <span class="text-slate-500 dark:text-slate-400">/ {{ $reseller->resellerPackage->max_users }}</span>
                                            @endif
                                        @elseif($reseller->directadmin_username)
                                            <span class="text-slate-400" title="Could not fetch from DirectAdmin">—</span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3">
                                        @if(($reseller->node_binding ?? '') === 'assigned')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">Assigned</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">Via services</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        @if($reseller->reseller_suspended_at)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300">Suspended</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">Active</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <!-- Node Information -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Hardware & Location -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Hardware & Location</h2>
            <div class="space-y-4">
                <div class="flex justify-between items-center pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400">CPU Cores</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $node->cpu_cores }}</span>
                </div>
                <div class="flex justify-between items-center pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400">RAM</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $node->ram_gb }} GB</span>
                </div>
                <div class="flex justify-between items-center pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400">Storage</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $node->storage_gb }} GB</span>
                </div>
                <div class="flex justify-between items-center pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400">Region</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $node->region ?? '-' }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-slate-600 dark:text-slate-400">Datacenter</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $node->datacenter ?? '-' }}</span>
                </div>
            </div>
        </div>

        <!-- Connection Details -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Connection Details</h2>
            <div class="space-y-4">
                <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">Hostname</span>
                    <code class="font-mono text-sm text-slate-900 dark:text-white">{{ $node->hostname }}</code>
                </div>
                <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">IP Address</span>
                    <code class="font-mono text-sm text-slate-900 dark:text-white">{{ $node->ip_address }}</code>
                </div>
                <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">SSH Port</span>
                    <code class="font-mono text-sm text-slate-900 dark:text-white">{{ $node->ssh_port }}</code>
                </div>
                @if($node->api_url)
                    <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">API URL</span>
                        <code class="font-mono text-sm text-slate-900 dark:text-white break-all">{{ $node->api_url }}</code>
                    </div>
                @endif
                <div>
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">Verify SSL</span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($node->verify_ssl)
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @else
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @endif
                    ">
                        {{ $node->verify_ssl ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Services Running on Node -->
    @php
        $servicesExpandedDefault = request()->has('page') ? 'true' : 'false';
    @endphp
    <div
        x-data="{ expanded: {{ $servicesExpandedDefault }} }"
        class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden"
    >
        <button
            type="button"
            @click="expanded = !expanded"
            class="w-full p-8 text-left flex items-center justify-between gap-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
        >
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Services Running ({{ $nodeServices->total() }})</h2>
                @if($nodeServices->total() > 0)
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Showing <strong class="text-slate-900 dark:text-white">{{ $nodeServices->firstItem() }}–{{ $nodeServices->lastItem() }}</strong>
                        of <strong class="text-slate-900 dark:text-white">{{ $nodeServices->total() }}</strong>
                        @if($nodeServices->hasPages())
                            · Page {{ $nodeServices->currentPage() }} of {{ $nodeServices->lastPage() }}
                        @endif
                    </p>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">No services running on this node.</p>
                @endif
            </div>
            <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400 shrink-0">
                <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                <svg class="w-5 h-5 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
        </button>

        <div x-show="expanded" x-cloak class="px-8 pb-8 border-t border-slate-200 dark:border-slate-800 pt-6">
            @if($nodeServices->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <th class="text-left py-3 font-semibold text-slate-900 dark:text-white">Service ID</th>
                                <th class="text-left py-3 font-semibold text-slate-900 dark:text-white">Product</th>
                                <th class="text-left py-3 font-semibold text-slate-900 dark:text-white">Customer</th>
                                <th class="text-left py-3 font-semibold text-slate-900 dark:text-white">Status</th>
                                <th class="text-left py-3 font-semibold text-slate-900 dark:text-white">Next Due</th>
                                <th class="text-right py-3 font-semibold text-slate-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($nodeServices as $service)
                                <tr class="border-b border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="py-4 font-medium text-slate-900 dark:text-white">#{{ $service->id }}</td>
                                    <td class="py-4">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $service->product->name }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                                    </td>
                                    <td class="py-4">
                                        <x-admin.customer-link :user="$service->user" class="text-slate-900 dark:text-white" />
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user->email }}</p>
                                    </td>
                                    <td class="py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($service->status->value === 'active')
                                                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                            @elseif($service->status->value === 'pending')
                                                bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                            @elseif($service->status->value === 'provisioning')
                                                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                            @elseif($service->status->value === 'suspended')
                                                bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                                            @elseif($service->status->value === 'terminated')
                                                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                            @endif
                                        ">
                                            {{ ucfirst($service->status->value) }}
                                        </span>
                                    </td>
                                    <td class="py-4 text-slate-600 dark:text-slate-400">
                                        {{ $service->next_due_date?->format('M d, Y') ?? '-' }}
                                    </td>
                                    <td class="py-4 text-right">
                                        <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($nodeServices->hasPages())
                    <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                        {{ $nodeServices->links() }}
                    </div>
                @endif
            @else
                <p class="text-slate-600 dark:text-slate-400 text-center py-6">No services running on this node.</p>
            @endif
        </div>
    </div>

    <!-- Node Metadata -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Metadata</h2>
        <div class="space-y-4">
            @if($node->description)
                <div>
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">Description</span>
                    <p class="text-slate-900 dark:text-white">{{ $node->description }}</p>
                </div>
            @endif
            <div class="grid grid-cols-2">
                <div>
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">Created</span>
                    <p class="text-slate-900 dark:text-white">{{ $node->created_at->format('M d, Y H:i') }}</p>
                </div>
                <div>
                    <span class="text-sm text-slate-600 dark:text-slate-400 block mb-1">Last Updated</span>
                    <p class="text-slate-900 dark:text-white">{{ $node->updated_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
