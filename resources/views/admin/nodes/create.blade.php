@extends('layouts.admin')

@section('title', 'Create Node')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Create Node</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header with Type Badge -->
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Add Infrastructure Node</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Configure a new server instance.</p>
        </div>
        @if($type === 'directadmin')
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-100 dark:bg-blue-950 rounded-lg">
                <div class="w-2 h-2 rounded-full bg-blue-600 dark:bg-blue-400"></div>
                <span class="text-sm font-medium text-blue-700 dark:text-blue-300">DirectAdmin Node</span>
            </div>
        @elseif($type === 'container_host')
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-purple-100 dark:bg-purple-950 rounded-lg">
                <div class="w-2 h-2 rounded-full bg-purple-600 dark:bg-purple-400"></div>
                <span class="text-sm font-medium text-purple-700 dark:text-purple-300">Container Server</span>
            </div>
        @endif
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.nodes.store') }}" class="space-y-8">
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">

            @if($type === 'directadmin')
                <!-- DIRECTADMIN NODE FORM -->

                <!-- Basic Information -->
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Basic Information</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Node Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="e.g. DA-Server-01" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                            @error('name')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- IP Address -->
                        <div>
                            <label for="ip_address" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">IP Address</label>
                            <input type="text" id="ip_address" name="ip_address" value="{{ old('ip_address') }}" placeholder="192.168.1.100" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ip_address') border-red-500 @enderror" required>
                            @error('ip_address')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Hostname -->
                        <div>
                            <label for="hostname" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Hostname</label>
                            <input type="text" id="hostname" name="hostname" value="{{ old('hostname') }}" placeholder="server01.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('hostname') border-red-500 @enderror" required>
                            @error('hostname')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- DA Admin Port -->
                        <div>
                            <label for="da_port" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">DirectAdmin Port</label>
                            <input type="text" id="da_port" name="da_port" value="{{ old('da_port') ?? '2222' }}" placeholder="2222" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('da_port') border-red-500 @enderror" required>
                            @error('da_port')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Region -->
                        <div>
                            <label for="region" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Region <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <input type="text" id="region" name="region" value="{{ old('region') }}" placeholder="US-East" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('region') border-red-500 @enderror">
                            @error('region')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Datacenter -->
                        <div>
                            <label for="datacenter" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Datacenter <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <input type="text" id="datacenter" name="datacenter" value="{{ old('datacenter') }}" placeholder="DC1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('datacenter') border-red-500 @enderror">
                            @error('datacenter')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Admin Credentials -->
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">DirectAdmin Credentials</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Username -->
                        <div>
                            <label for="ssh_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Admin Username</label>
                            <input type="text" id="ssh_username" name="ssh_username" value="{{ old('ssh_username') }}" placeholder="admin" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_username') border-red-500 @enderror" required>
                            @error('ssh_username')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Authentication Method Toggle -->
                        <div x-data="{ useLoginKey: false }">
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Authentication Method</label>
                            <div class="flex items-center gap-2 p-3 border border-slate-300 dark:border-slate-600 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                                <input type="radio" id="auth_password" name="auth_method" value="password" x-model="useLoginKey" :value="false" class="w-4 h-4 text-blue-600">
                                <label for="auth_password" class="flex-1 text-sm text-slate-700 dark:text-slate-300 cursor-pointer">Password</label>
                                <input type="radio" id="auth_key" name="auth_method" value="key" x-model="useLoginKey" :value="true" class="w-4 h-4 text-blue-600">
                                <label for="auth_key" class="flex-1 text-sm text-slate-700 dark:text-slate-300 cursor-pointer">Login Key</label>
                            </div>
                        </div>

                        <!-- Password -->
                        <div x-data="{ useLoginKey: false }">
                            <label for="ssh_password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                                Password
                                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(or leave blank if using key)</span>
                            </label>
                            <input type="password" id="ssh_password" name="ssh_password" placeholder="Your admin password" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_password') border-red-500 @enderror">
                            @error('ssh_password')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Login Key -->
                        <div>
                            <label for="da_login_key" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                                Login Key
                                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(preferred authentication)</span>
                            </label>
                            <textarea id="da_login_key" name="da_login_key" rows="4" placeholder="Paste your DirectAdmin login key here..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none font-mono text-xs @error('da_login_key') border-red-500 @enderror">{{ old('da_login_key') }}</textarea>
                            @error('da_login_key')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

            @elseif($type === 'container_host')
                <!-- CONTAINER SERVER FORM -->

                <!-- Basic Information -->
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Basic Information</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Node Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="e.g. Container-Host-01" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                            @error('name')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- IP Address -->
                        <div>
                            <label for="ip_address" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">IP Address</label>
                            <input type="text" id="ip_address" name="ip_address" value="{{ old('ip_address') }}" placeholder="192.168.1.100" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ip_address') border-red-500 @enderror" required>
                            @error('ip_address')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Hostname -->
                        <div>
                            <label for="hostname" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Hostname</label>
                            <input type="text" id="hostname" name="hostname" value="{{ old('hostname') }}" placeholder="container.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('hostname') border-red-500 @enderror" required>
                            @error('hostname')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- SSH Port -->
                        <div>
                            <label for="ssh_port" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Port</label>
                            <input type="text" id="ssh_port" name="ssh_port" value="{{ old('ssh_port') ?? '22' }}" placeholder="22" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_port') border-red-500 @enderror" required>
                            @error('ssh_port')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Region -->
                        <div>
                            <label for="region" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Region <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <input type="text" id="region" name="region" value="{{ old('region') }}" placeholder="US-East" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('region') border-red-500 @enderror">
                            @error('region')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Datacenter -->
                        <div>
                            <label for="datacenter" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Datacenter <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <input type="text" id="datacenter" name="datacenter" value="{{ old('datacenter') }}" placeholder="DC1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('datacenter') border-red-500 @enderror">
                            @error('datacenter')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Root Access Credentials -->
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Root Access Credentials</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- SSH Username -->
                        <div>
                            <label for="ssh_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Username</label>
                            <input type="text" id="ssh_username" name="ssh_username" value="{{ old('ssh_username') ?? 'root' }}" placeholder="root" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_username') border-red-500 @enderror" required>
                            @error('ssh_username')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- SSH Password -->
                        <div>
                            <label for="ssh_password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">SSH Password</label>
                            <input type="password" id="ssh_password" name="ssh_password" placeholder="Your root password" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ssh_password') border-red-500 @enderror">
                            @error('ssh_password')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Hardware Specifications -->
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Hardware Specifications</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- CPU Cores -->
                        <div>
                            <label for="cpu_cores" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">CPU Cores</label>
                            <input type="number" id="cpu_cores" name="cpu_cores" value="{{ old('cpu_cores') }}" placeholder="8" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('cpu_cores') border-red-500 @enderror" required>
                            @error('cpu_cores')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RAM GB -->
                        <div>
                            <label for="ram_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM (GB)</label>
                            <input type="number" id="ram_gb" name="ram_gb" value="{{ old('ram_gb') }}" placeholder="32" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('ram_gb') border-red-500 @enderror" required>
                            @error('ram_gb')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Storage GB -->
                        <div>
                            <label for="storage_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage (GB)</label>
                            <input type="number" id="storage_gb" name="storage_gb" value="{{ old('storage_gb') }}" placeholder="500" min="1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('storage_gb') border-red-500 @enderror" required>
                            @error('storage_gb')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

            @else
                <!-- Fallback: No type selected -->
                <div class="p-6 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-lg">
                    <p class="text-amber-800 dark:text-amber-200">No node type selected. <a href="{{ route('admin.nodes.index') }}" class="font-semibold hover:underline">Go back</a> and choose a node type.</p>
                </div>
            @endif

            @if($type === 'directadmin' || $type === 'container_host')
                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                    <textarea id="description" name="description" rows="3" placeholder="Notes about this node..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Active Status -->
                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('is_active') === '1' || old('is_active') === true)>
                        <span class="text-sm text-slate-700 dark:text-slate-300">Node is Active</span>
                    </label>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                    <a href="{{ route('admin.nodes.index') }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                        Cancel
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="window.location.href='{{ route('admin.nodes.index') }}'" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition border border-slate-300 dark:border-slate-600 rounded-lg">
                            Change Type
                        </button>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Create Node
                        </button>
                    </div>
                </div>
            @endif
        </form>
    </div>
</div>
@endsection
