@extends('layouts.reseller')

@section('title', 'Nodes')

@section('content')
@php
    $d = $dashboard;
    $node = $d['node'];
@endphp

<div class="space-y-6" x-data="resellerNodesConnect()">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Infrastructure</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Connect your DirectAdmin reseller account to provision hosting, sync usage, and manage package limits.
            </p>
        </div>
        @if($d['is_connected'])
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('reseller.nodes.index', ['refresh' => 1]) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refresh
                </a>
                @if($d['control_panel_url'])
                    <a href="{{ $d['control_panel_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-purple-600 hover:bg-purple-700 text-white transition">
                        Open DirectAdmin
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                @endif
            </div>
        @endif
    </div>

    @if($d['is_connected'])
        {{-- Connected dashboard --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Connection status --}}
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-start justify-between gap-4 mb-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex w-2.5 h-2.5 rounded-full {{ $d['api_reachable'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">
                                {{ $d['api_reachable'] ? 'Connected' : 'Connection issue' }}
                            </h3>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            Reseller account <code class="font-mono text-purple-700 dark:text-purple-300">{{ $d['directadmin_username'] }}</code>
                            on {{ $node?->name ?? 'server' }}
                        </p>
                        @if($d['connected_at'])
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Linked {{ \Carbon\Carbon::parse($d['connected_at'])->diffForHumans() }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('reseller.nodes.disconnect') }}" onsubmit="return confirm('Disconnect DirectAdmin? Catalog items and provisioning will stop until you reconnect.');">
                        @csrf
                        <button type="submit" class="text-sm text-red-600 dark:text-red-400 hover:underline">Disconnect</button>
                    </form>
                </div>

                @if(!$d['api_reachable'])
                    <div class="mb-6 p-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-200">
                        Could not reach the DirectAdmin API. Try refreshing, or contact your provider if the server credentials changed.
                    </div>
                @endif

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                        <dt class="text-slate-500 dark:text-slate-400">Server</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5">{{ $node?->name }}</dd>
                        <dd class="text-xs text-slate-500 font-mono">{{ $node?->hostname }}</dd>
                    </div>
                    <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                        <dt class="text-slate-500 dark:text-slate-400">Region</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5">{{ $node?->region ?: '—' }}</dd>
                    </div>
                    <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                        <dt class="text-slate-500 dark:text-slate-400">Portal hosting services</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5">{{ $d['platform_services_on_node'] }}</dd>
                    </div>
                    <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                        <dt class="text-slate-500 dark:text-slate-400">Catalog plans linked</dt>
                        <dd class="font-medium text-slate-900 dark:text-white mt-0.5">{{ $d['da_catalog_items'] }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Quick actions --}}
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase tracking-wide">Quick actions</h3>
                <a href="{{ route('reseller.catalog.create') }}" class="flex items-center gap-3 p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <span class="text-purple-600 dark:text-purple-400">+</span>
                    <span class="text-sm font-medium text-slate-900 dark:text-white">Add catalog plan</span>
                </a>
                <a href="{{ route('reseller.customer-orders.hosting.create') }}" class="flex items-center gap-3 p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <span class="text-purple-600 dark:text-purple-400">→</span>
                    <span class="text-sm font-medium text-slate-900 dark:text-white">Order hosting for customer</span>
                </a>
                <a href="{{ route('reseller.packages.index') }}" class="flex items-center gap-3 p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <span class="text-purple-600 dark:text-purple-400">◎</span>
                    <span class="text-sm font-medium text-slate-900 dark:text-white">View package limits</span>
                </a>
            </div>
        </div>

        {{-- Usage meters --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Hosted users</h3>
                    <span class="text-sm text-slate-600 dark:text-slate-400">
                        {{ $d['hosted_user_count'] ?? '—' }}
                        @if($d['max_users'] > 0)
                            / {{ $d['max_users'] }}
                        @endif
                    </span>
                </div>
                @if($d['max_users'] > 0 && $d['hosted_user_count'] !== null)
                    @php
                        $userPct = $d['user_limit_percent'] ?? 0;
                        $userColor = $userPct >= 90 ? 'bg-red-500' : ($userPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                    @endphp
                    <div class="w-full h-3 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="{{ $userColor }} h-3 rounded-full transition-all" style="width: {{ $userPct }}%"></div>
                    </div>
                @endif
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                    @if(($d['hosted_user_count_source'] ?? '') === 'directadmin')
                        All end-user accounts on your DirectAdmin reseller, including accounts created outside this portal.
                    @else
                        Portal customer count (connect DirectAdmin for live server totals).
                    @endif
                </p>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Disk pool</h3>
                    <span class="text-sm text-slate-600 dark:text-slate-400">
                        @if($d['disk_used_gb'] !== null && $d['disk_pool_gb'] > 0)
                            {{ number_format($d['disk_used_gb'], 1) }} / {{ $d['disk_pool_gb'] }} GB
                        @elseif($d['disk_used_gb'] !== null)
                            {{ number_format($d['disk_used_gb'], 1) }} GB used
                        @else
                            —
                        @endif
                    </span>
                </div>
                @if($d['disk_pool_percent'] !== null)
                    @php
                        $diskPct = $d['disk_pool_percent'];
                        $diskColor = $diskPct >= 90 ? 'bg-red-500' : ($diskPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                    @endphp
                    <div class="w-full h-3 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="{{ $diskColor }} h-3 rounded-full transition-all" style="width: {{ $diskPct }}%"></div>
                    </div>
                @endif
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Aggregated disk across all accounts on your DirectAdmin reseller.</p>
            </div>
        </div>

        {{-- Packages --}}
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-slate-900 dark:text-white">DirectAdmin packages</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Hosting plans available to link in your catalog</p>
                </div>
                <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ count($d['packages']) }} package(s)</span>
            </div>

            @if($d['packages_error'])
                <div class="p-6 text-sm text-amber-700 dark:text-amber-300">{{ $d['packages_error'] }}</div>
            @elseif(empty($d['packages']))
                <div class="p-8 text-center">
                    <p class="text-slate-600 dark:text-slate-400 text-sm">No user packages found on your reseller account.</p>
                    <p class="text-xs text-slate-500 dark:text-slate-500 mt-2">Create packages in DirectAdmin, then refresh this page.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="px-6 py-3 font-medium">Package</th>
                                <th class="px-6 py-3 font-medium">Disk</th>
                                <th class="px-6 py-3 font-medium">Bandwidth</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($d['packages'] as $package)
                                <tr>
                                    <td class="px-6 py-3 font-medium text-slate-900 dark:text-white">{{ $package['name'] }}</td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        @if(($package['disk_quota'] ?? 0) < 0)
                                            Unlimited
                                        @elseif(($package['disk_quota'] ?? 0) > 0)
                                            {{ $package['disk_quota'] }} GB
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        @if(($package['bandwidth_quota'] ?? 0) < 0)
                                            Unlimited
                                        @elseif(($package['bandwidth_quota'] ?? 0) > 0)
                                            {{ $package['bandwidth_quota'] }} GB
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @else
        {{-- Connect form --}}
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-3 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Connect DirectAdmin</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">
                    Enter the reseller username from your DirectAdmin panel. We verify it against the platform server — you do not need to share your DirectAdmin password here.
                </p>

                @if($d['available_nodes']->isEmpty())
                    <div class="p-4 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-200">
                        No DirectAdmin servers are available yet. Ask your provider to configure a hosting node before connecting.
                    </div>
                @else
                    <form method="POST" action="{{ route('reseller.nodes.connect') }}" class="space-y-5" @submit="onSubmit">
                        @csrf

                        <div>
                            <label for="reseller_node_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Hosting server</label>
                            <select
                                id="reseller_node_id"
                                name="reseller_node_id"
                                x-model="nodeId"
                                required
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 text-slate-900 dark:text-white text-sm @error('reseller_node_id') border-red-500 @enderror"
                            >
                                <option value="">Select a server...</option>
                                @foreach($d['available_nodes'] as $availableNode)
                                    <option value="{{ $availableNode->id }}" @selected(old('reseller_node_id') == $availableNode->id)>
                                        {{ $availableNode->name }} ({{ $availableNode->hostname }})
                                    </option>
                                @endforeach
                            </select>
                            @error('reseller_node_id')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="directadmin_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">DirectAdmin username</label>
                            <input
                                type="text"
                                id="directadmin_username"
                                name="directadmin_username"
                                x-model="username"
                                value="{{ old('directadmin_username') }}"
                                placeholder="e.g. reseller_acme"
                                autocomplete="off"
                                required
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 text-slate-900 dark:text-white text-sm font-mono @error('directadmin_username') border-red-500 @enderror"
                            >
                            @error('directadmin_username')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Must match your reseller login on DirectAdmin exactly.</p>
                        </div>

                        <div x-show="testMessage" x-cloak class="p-4 rounded-lg text-sm" :class="testSuccess ? 'bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200' : 'bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200'">
                            <p x-text="testMessage"></p>
                            <template x-if="testSuccess && testPackages.length">
                                <p class="mt-1 text-xs opacity-80" x-text="`${testPackages.length} package(s) · ${testUsers ?? 0} hosted user(s)`"></p>
                            </template>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                @click="runTest"
                                :disabled="testing || !nodeId || !username"
                                class="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-50 transition"
                            >
                                <span x-show="!testing">Test connection</span>
                                <span x-show="testing">Testing...</span>
                            </button>
                            <button
                                type="submit"
                                :disabled="connecting"
                                class="px-4 py-2 text-sm font-medium rounded-lg bg-purple-600 hover:bg-purple-700 text-white disabled:opacity-50 transition"
                            >
                                Connect account
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            <div class="lg:col-span-2 space-y-4">
                <div class="bg-purple-50 dark:bg-purple-950/30 rounded-xl border border-purple-200 dark:border-purple-800 p-5">
                    <h4 class="font-semibold text-slate-900 dark:text-white text-sm mb-3">What you get after connecting</h4>
                    <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-400">
                        <li class="flex gap-2"><span class="text-purple-600">✓</span> Auto-provision customer hosting under your reseller</li>
                        <li class="flex gap-2"><span class="text-purple-600">✓</span> Link DirectAdmin packages in your catalog</li>
                        <li class="flex gap-2"><span class="text-purple-600">✓</span> Live hosted user and disk usage for package limits</li>
                        <li class="flex gap-2"><span class="text-purple-600">✓</span> Server-side suspend when your subscription lapses</li>
                    </ul>
                </div>

                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
                    <h4 class="font-semibold text-slate-900 dark:text-white text-sm mb-2">Before you start</h4>
                    <ol class="list-decimal list-inside space-y-1.5 text-sm text-slate-600 dark:text-slate-400">
                        <li>Your provider creates your DirectAdmin reseller account</li>
                        <li>Create user hosting packages in DirectAdmin</li>
                        <li>Enter the same username here to link accounts</li>
                    </ol>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
function resellerNodesConnect() {
    return {
        nodeId: @json(old('reseller_node_id', '')),
        username: @json(old('directadmin_username', '')),
        testing: false,
        connecting: false,
        testMessage: '',
        testSuccess: false,
        testPackages: [],
        testUsers: null,
        async runTest() {
            if (!this.nodeId || !this.username) return;
            this.testing = true;
            this.testMessage = '';
            try {
                const response = await fetch(@json(route('reseller.nodes.test')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        reseller_node_id: parseInt(this.nodeId, 10),
                        directadmin_username: this.username,
                    }),
                });
                const data = await response.json();
                this.testSuccess = data.success;
                this.testMessage = data.message;
                this.testPackages = data.packages || [];
                this.testUsers = data.hosted_user_count;
            } catch (e) {
                this.testSuccess = false;
                this.testMessage = 'Connection test failed. Try again.';
            } finally {
                this.testing = false;
            }
        },
        onSubmit() {
            this.connecting = true;
        },
    };
}
</script>
@endpush
