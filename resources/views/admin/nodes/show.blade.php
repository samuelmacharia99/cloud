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
            <a href="{{ route('admin.nodes.edit', $node) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                Edit Node
            </a>
            <form method="POST" action="{{ route('admin.nodes.delete', $node) }}" class="inline" onsubmit="return confirm('Are you sure? This cannot be undone unless the node has no active services.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                    Delete
                </button>
            </form>
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
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $node->services_count ?? 0 }}</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Last Heartbeat</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $node->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</p>
        </div>
    </div>

    <!-- Utilization -->
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
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Services Running ({{ $node->services_count ?? 0 }})</h2>

        @if($node->services->count() > 0)
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
                        @foreach($node->services as $service)
                            <tr class="border-b border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="py-4 font-medium text-slate-900 dark:text-white">#{{ $service->id }}</td>
                                <td class="py-4">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $service->product->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                                </td>
                                <td class="py-4">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $service->user->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user->email }}</p>
                                </td>
                                <td class="py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($service->status === 'active')
                                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                        @elseif($service->status === 'pending')
                                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                        @elseif($service->status === 'provisioning')
                                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                        @elseif($service->status === 'suspended')
                                            bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                                        @elseif($service->status === 'terminated')
                                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                        @endif
                                    ">
                                        {{ ucfirst($service->status) }}
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
        @else
            <p class="text-slate-600 dark:text-slate-400 text-center py-6">No services running on this node.</p>
        @endif
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
