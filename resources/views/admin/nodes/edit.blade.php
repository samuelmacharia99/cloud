@extends('layouts.admin')

@section('title', 'Edit Node: ' . $node->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.nodes.show', $node) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $node->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Node</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update node configuration and settings.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.nodes.update', $node) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Basic Information -->
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Basic Information</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Node Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $node->name) }}" placeholder="e.g. US-East-01" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Type -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Node Type</label>
                        <select id="type" name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('type') border-red-500 @enderror" required>
                            <option value="">Select a type...</option>
                            <option value="dedicated_server" @selected(old('type', $node->type) === 'dedicated_server')>Dedicated Server</option>
                            <option value="container_host" @selected(old('type', $node->type) === 'container_host')>Container Host</option>
                            <option value="load_balancer" @selected(old('type', $node->type) === 'load_balancer')>Load Balancer</option>
                            <option value="database_server" @selected(old('type', $node->type) === 'database_server')>Database Server</option>
                            <option value="directadmin" @selected(old('type', $node->type) === 'directadmin')>DirectAdmin Server</option>
                        </select>
                        @error('type')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Hostname -->
                    <div>
                        <label for="hostname" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Hostname</label>
                        <input type="text" id="hostname" name="hostname" value="{{ old('hostname', $node->hostname) }}" placeholder="server01.internal" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('hostname') border-red-500 @enderror" required>
                        @error('hostname')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- IP Address -->
                    <div>
                        <label for="ip_address" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">IP Address</label>
                        <input type="text" id="ip_address" name="ip_address" value="{{ old('ip_address', $node->ip_address) }}" placeholder="192.168.1.100" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ip_address') border-red-500 @enderror" required>
                        @error('ip_address')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Region -->
                    <div>
                        <label for="region" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Region</label>
                        <input type="text" id="region" name="region" value="{{ old('region', $node->region) }}" placeholder="US-East" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('region') border-red-500 @enderror">
                        @error('region')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Datacenter -->
                    <div>
                        <label for="datacenter" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Datacenter</label>
                        <input type="text" id="datacenter" name="datacenter" value="{{ old('datacenter', $node->datacenter) }}" placeholder="DC1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('datacenter') border-red-500 @enderror">
                        @error('datacenter')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div>
                <label for="status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status</label>
                <select id="status" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('status') border-red-500 @enderror" required>
                    <option value="">Select status...</option>
                    <option value="online" @selected(old('status', $node->status) === 'online')>Online</option>
                    <option value="offline" @selected(old('status', $node->status) === 'offline')>Offline</option>
                    <option value="degraded" @selected(old('status', $node->status) === 'degraded')>Degraded</option>
                    <option value="maintenance" @selected(old('status', $node->status) === 'maintenance')>Maintenance</option>
                </select>
                @error('status')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- Hardware Specifications -->
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Hardware Specifications</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- CPU Cores -->
                    <div>
                        <label for="cpu_cores" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">CPU Cores</label>
                        <input type="number" id="cpu_cores" name="cpu_cores" value="{{ old('cpu_cores', $node->cpu_cores) }}" placeholder="8" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('cpu_cores') border-red-500 @enderror" required>
                        @error('cpu_cores')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- RAM GB -->
                    <div>
                        <label for="ram_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM (GB)</label>
                        <input type="number" id="ram_gb" name="ram_gb" value="{{ old('ram_gb', $node->ram_gb) }}" placeholder="32" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ram_gb') border-red-500 @enderror" required>
                        @error('ram_gb')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Storage GB -->
                    <div>
                        <label for="storage_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage (GB)</label>
                        <input type="number" id="storage_gb" name="storage_gb" value="{{ old('storage_gb', $node->storage_gb) }}" placeholder="500" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('storage_gb') border-red-500 @enderror" required>
                        @error('storage_gb')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Connection Details -->
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Connection Details</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- SSH Port -->
                    <div>
                        <label for="ssh_port" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Port</label>
                        <input type="text" id="ssh_port" name="ssh_port" value="{{ old('ssh_port', $node->ssh_port) }}" placeholder="22" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_port') border-red-500 @enderror" required>
                        @error('ssh_port')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- API URL -->
                    <div>
                        <label for="api_url" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">API URL <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="url" id="api_url" name="api_url" value="{{ old('api_url', $node->api_url) }}" placeholder="https://api.node.internal" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('api_url') border-red-500 @enderror">
                        @error('api_url')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- API Token -->
                    <div>
                        <label for="api_token" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">API Token <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="password" id="api_token" name="api_token" placeholder="Leave blank to keep existing token" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('api_token') border-red-500 @enderror">
                        @error('api_token')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Verify SSL -->
                    <div class="flex items-end">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="verify_ssl" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('verify_ssl', $node->verify_ssl) === '1' || old('verify_ssl', $node->verify_ssl) === true)>
                            <span class="text-sm text-slate-700 dark:text-slate-300">Verify SSL Certificate</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                <textarea id="description" name="description" rows="3" placeholder="Notes about this node..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('description', $node->description) }}</textarea>
                @error('description')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- Active Status -->
            <div>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('is_active', $node->is_active) === '1' || old('is_active', $node->is_active) === true)>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Node is Active</span>
                </label>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.nodes.show', $node) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Update Node
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
