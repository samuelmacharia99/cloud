@php
    $d = $dashboard;
    $node = $d['node'];
@endphp

<div class="space-y-6" x-data="adminResellerNodeTab(@js([
    'testUrl' => route('admin.resellers.directadmin.test', $user),
    'nodeId' => old('reseller_node_id', $user->reseller_node_id ?? ''),
    'username' => old('directadmin_username', $user->directadmin_username ?? ''),
    'hasStoredKey' => filled($user->directadmin_login_key),
]))">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin node</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Link this reseller to their DirectAdmin account using their API login key. Customer hosting auto-provisions after invoice payment when username, server, and key are saved here.
            </p>
        </div>
        @if($d['is_connected'])
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.resellers.show', ['user' => $user, 'tab' => 'node', 'refresh_node' => 1]) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Refresh stats
                </a>
                @if($user->directadmin_username)
                    <a href="{{ route('admin.resellers.directadmin.panel-login', $user) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition">
                        Open DirectAdmin
                    </a>
                @endif
            </div>
        @endif
    </div>

    @if($d['is_connected'])
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
                <div class="flex items-start justify-between gap-4 mb-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex w-2.5 h-2.5 rounded-full {{ $d['api_reachable'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $d['api_reachable'] ? 'Linked' : 'API unreachable' }}</span>
                            @if($d['provisioning_ready'] ?? false)
                                <span class="ml-2 text-xs font-normal text-emerald-600 dark:text-emerald-400">Auto-provision ready</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            <code class="font-mono text-blue-700 dark:text-blue-300">{{ $d['directadmin_username'] }}</code>
                            on {{ $node?->name }}
                        </p>
                        @if($d['connected_at'])
                            <p class="text-xs text-slate-500 mt-1">Linked {{ \Carbon\Carbon::parse($d['connected_at'])->diffForHumans() }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.resellers.directadmin.disconnect', $user) }}" onsubmit="return confirm('Remove DirectAdmin link for this reseller?');">
                        @csrf
                        <button type="submit" class="text-sm text-red-600 dark:text-red-400 hover:underline">Unlink</button>
                    </form>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-slate-500">Hostname</dt>
                        <dd class="font-mono text-slate-900 dark:text-white">{{ $node?->hostname }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Region</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $node?->region ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Portal services on node</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $d['platform_services_on_node'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">DA catalog plans</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $d['da_catalog_items'] }}</dd>
                    </div>
                </dl>
            </div>

            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
                    <p class="text-xs font-medium text-slate-500 uppercase mb-2">Hosted users</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        {{ $d['hosted_user_count'] ?? '—' }}
                        @if($d['max_users'] > 0)
                            <span class="text-sm font-normal text-slate-500">/ {{ $d['max_users'] }}</span>
                        @endif
                    </p>
                    @if($d['user_limit_percent'] !== null)
                        <div class="mt-2 w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-2 rounded-full {{ ($d['user_limit_percent'] ?? 0) >= 90 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $d['user_limit_percent'] }}%"></div>
                        </div>
                    @endif
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
                    <p class="text-xs font-medium text-slate-500 uppercase mb-2">Disk pool</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        @if($d['disk_used_gb'] !== null && $d['disk_pool_gb'] > 0)
                            {{ number_format($d['disk_used_gb'], 1) }} <span class="text-sm font-normal text-slate-500">/ {{ $d['disk_pool_gb'] }} GB</span>
                        @elseif($d['disk_used_gb'] !== null)
                            {{ number_format($d['disk_used_gb'], 1) }} GB
                        @else
                            —
                        @endif
                    </p>
                    @if($d['disk_pool_percent'] !== null)
                        <div class="mt-2 w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-2 rounded-full {{ ($d['disk_pool_percent'] ?? 0) >= 90 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $d['disk_pool_percent'] }}%"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                <h4 class="font-semibold text-slate-900 dark:text-white">Reseller packages on DirectAdmin</h4>
                <span class="text-sm text-slate-500">{{ count($d['packages']) }} package(s)</span>
            </div>
            @if($d['packages_error'])
                <p class="p-6 text-sm text-amber-700 dark:text-amber-300">{{ $d['packages_error'] }}</p>
            @elseif(empty($d['packages']))
                <p class="p-6 text-sm text-slate-500">No user packages on this reseller account yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Package</th>
                                <th class="px-6 py-3">Disk</th>
                                <th class="px-6 py-3">Bandwidth</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($d['packages'] as $package)
                                <tr>
                                    <td class="px-6 py-3 font-medium">{{ $package['name'] }}</td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        @if(($package['disk_quota'] ?? 0) < 0) Unlimited @elseif(($package['disk_quota'] ?? 0) > 0) {{ $package['disk_quota'] }} GB @else — @endif
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        @if(($package['bandwidth_quota'] ?? 0) < 0) Unlimited @elseif(($package['bandwidth_quota'] ?? 0) > 0) {{ $package['bandwidth_quota'] }} GB @else — @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
            <h4 class="font-semibold text-slate-900 dark:text-white mb-4">Update link</h4>
            <form method="POST" action="{{ route('admin.resellers.directadmin.connect', $user) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                <div>
                    <label for="node_tab_server" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Server</label>
                    <select id="node_tab_server" name="reseller_node_id" x-model="nodeId" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                        @foreach($d['available_nodes'] as $availableNode)
                            <option value="{{ $availableNode->id }}">{{ $availableNode->name }} ({{ $availableNode->hostname }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="node_tab_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DirectAdmin username</label>
                    <input type="text" id="node_tab_username" name="directadmin_username" x-model="username" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono">
                </div>
                <div class="md:col-span-2">
                    <label for="node_tab_login_key" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DirectAdmin login key</label>
                    <textarea id="node_tab_login_key" name="directadmin_login_key" x-model="loginKey" rows="3" placeholder="Leave blank to keep the existing key" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono"></textarea>
                    @if($d['has_login_key'] ?? false)
                        <p class="mt-1 text-xs text-slate-500">A login key is stored. Leave blank to keep it when updating username or server.</p>
                    @else
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">Required for customer hosting to auto-provision after payment.</p>
                    @endif
                </div>
                <div class="md:col-span-2 flex flex-wrap gap-3">
                    <button type="button" @click="runTest()" :disabled="testing || !nodeId || !username || (!loginKey && !hasStoredKey)" class="px-4 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-white dark:hover:bg-slate-900 disabled:opacity-50">Test</button>
                    <button type="submit" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Save link</button>
                </div>
                <div x-show="testMessage" x-cloak class="md:col-span-2 p-3 rounded-lg text-sm" :class="testSuccess ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200'" x-text="testMessage"></div>
            </form>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
                <h4 class="font-semibold text-slate-900 dark:text-white mb-4">Link DirectAdmin account</h4>

                @if($d['available_nodes']->isEmpty())
                    <p class="text-sm text-amber-700 dark:text-amber-300">No active DirectAdmin nodes with API credentials. Add a node under Infrastructure first.</p>
                @else
                    <form method="POST" action="{{ route('admin.resellers.directadmin.connect', $user) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="connect_node_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Server</label>
                            <select id="connect_node_id" name="reseller_node_id" x-model="nodeId" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm @error('reseller_node_id') border-red-500 @enderror">
                                <option value="">Select server...</option>
                                @foreach($d['available_nodes'] as $availableNode)
                                    <option value="{{ $availableNode->id }}">{{ $availableNode->name }} ({{ $availableNode->hostname }})</option>
                                @endforeach
                            </select>
                            @error('reseller_node_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="connect_da_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DirectAdmin username</label>
                            <input type="text" id="connect_da_username" name="directadmin_username" x-model="username" value="{{ old('directadmin_username') }}" placeholder="reseller_acme" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono @error('directadmin_username') border-red-500 @enderror">
                            @error('directadmin_username')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="connect_da_login_key" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DirectAdmin login key</label>
                            <textarea id="connect_da_login_key" name="directadmin_login_key" x-model="loginKey" rows="4" required placeholder="Paste the reseller API login key from DirectAdmin" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono @error('directadmin_login_key') border-red-500 @enderror"></textarea>
                            @error('directadmin_login_key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="mt-1 text-xs text-slate-500">Create under DirectAdmin → Login Keys for the reseller account. Required for auto-provisioning customer hosting.</p>
                        </div>
                        <div x-show="testMessage" x-cloak class="p-3 rounded-lg text-sm" :class="testSuccess ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200'">
                            <p x-text="testMessage"></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" @click="runTest()" :disabled="testing || !nodeId || !username || (!loginKey && !hasStoredKey)" class="px-4 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg disabled:opacity-50">Test connection</button>
                            <button type="submit" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Link account</button>
                        </div>
                    </form>
                @endif
            </div>
            <div class="lg:col-span-2 bg-blue-50 dark:bg-blue-950/30 rounded-xl border border-blue-200 dark:border-blue-800 p-5 text-sm text-slate-600 dark:text-slate-400 space-y-2">
                <p class="font-semibold text-slate-900 dark:text-white">Setup checklist</p>
                <p>1. Create the reseller account in DirectAdmin.</p>
                <p>2. Create a login key for that reseller in DirectAdmin.</p>
                <p>3. Create user hosting packages under that reseller.</p>
                <p>4. Enter server, username, and login key here.</p>
                <p class="text-xs pt-2 border-t border-blue-200 dark:border-blue-800">After a customer pays, hosting is created on DirectAdmin using the reseller login key.</p>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function adminResellerNodeTab(config) {
    return {
        nodeId: config.nodeId ? String(config.nodeId) : '',
        username: config.username || '',
        loginKey: '',
        hasStoredKey: config.hasStoredKey || false,
        testing: false,
        testMessage: '',
        testSuccess: false,
        async runTest() {
            if (!this.nodeId || !this.username || (!this.loginKey && !this.hasStoredKey)) return;
            this.testing = true;
            this.testMessage = '';
            try {
                const response = await fetch(config.testUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        reseller_node_id: parseInt(this.nodeId, 10),
                        directadmin_username: this.username,
                        directadmin_login_key: this.loginKey || null,
                    }),
                });
                const data = await response.json();
                this.testSuccess = data.success;
                this.testMessage = data.message + (data.success && data.hosted_user_count !== null ? ` (${data.hosted_user_count} hosted user(s))` : '');
            } catch (e) {
                this.testSuccess = false;
                this.testMessage = 'Connection test failed.';
            } finally {
                this.testing = false;
            }
        },
    };
}
</script>
@endpush
