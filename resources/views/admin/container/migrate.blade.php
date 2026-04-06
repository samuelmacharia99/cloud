@extends('layouts.admin')

@section('title', 'Migrate Container')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.services.show', $service) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $service->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Migrate Container</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Migrate Container</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Move this container to a different hosting node.</p>
    </div>

    <!-- Service Info Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Service Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Service Name</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $service->name }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Product</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $service->product?->name }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Current Node</p>
                <p class="font-semibold text-slate-900 dark:text-white">
                    @if ($deployment->node)
                        <a href="{{ route('admin.nodes.show', $deployment->node) }}" class="text-blue-600 hover:text-blue-700">
                            {{ $deployment->node->hostname }}
                        </a>
                    @else
                        <span class="text-red-600">Unassigned</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-1">Container Name</p>
                <p class="font-mono text-sm text-slate-900 dark:text-white">{{ $deployment->container_name }}</p>
            </div>
        </div>
    </div>

    <!-- Warning Banner -->
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-6">
        <div class="flex gap-4">
            <div class="flex-shrink-0">
                <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-amber-900 dark:text-amber-200 mb-1">Important Notice</h3>
                <p class="text-sm text-amber-800 dark:text-amber-300">
                    Volume data will NOT be transferred. The application will be redeployed fresh on the target node with new volumes. Any persistent data must be backed up separately.
                </p>
            </div>
        </div>
    </div>

    <!-- Migration Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.services.container.migrate.confirm', $service) }}" class="space-y-6">
            @csrf

            <!-- Target Node Selection -->
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-4">Select Target Node</label>

                @if ($availableTargets->isEmpty())
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <p class="text-sm text-red-700 dark:text-red-200">No available target nodes. Ensure at least one additional container host is active.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($availableTargets as $target)
                            @php
                                $containerCount = $target->containerDeployments()->whereIn('status', ['running', 'stopped'])->count();
                                $cpuUsage = $target->cpu_usage_percent ?? 0;
                                $ramUsage = $target->ram_usage_percent ?? 0;
                            @endphp
                            <label class="flex items-start gap-4 p-4 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer transition">
                                <input type="radio" name="target_node_id" value="{{ $target->id }}" class="mt-1" required>
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white">{{ $target->hostname }}</p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ $target->ip_address }}</p>
                                    <div class="flex gap-4 mt-2 text-xs">
                                        <span class="text-slate-600 dark:text-slate-400">Containers: <strong>{{ $containerCount }}</strong></span>
                                        <span class="text-slate-600 dark:text-slate-400">CPU: <strong>{{ $cpuUsage }}%</strong></span>
                                        <span class="text-slate-600 dark:text-slate-400">RAM: <strong>{{ $ramUsage }}%</strong></span>
                                    </div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('target_node_id')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            <!-- Migration Reason -->
            <div>
                <label for="reason" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Migration Reason</label>
                <select id="reason" name="reason" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                    <option value="">Select a reason...</option>
                    <option value="planned_maintenance">Planned Maintenance</option>
                    <option value="node_failure">Node Failure</option>
                    <option value="rebalancing">Rebalancing</option>
                    <option value="upgrade">Upgrade</option>
                    <option value="manual">Manual</option>
                </select>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Optional: Document why this migration is happening</p>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.services.show', $service) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition" @if ($availableTargets->isEmpty()) disabled @endif>
                    Confirm Migration
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
