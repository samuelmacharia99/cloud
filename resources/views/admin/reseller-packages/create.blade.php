@extends('layouts.admin')

@section('title', 'Create Reseller Package')

@section('content')
<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <a href="{{ route('admin.reseller-packages.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm mb-3 inline-block">
            ← Back to Packages
        </a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create Reseller Package</h1>
    </div>

    <!-- Form -->
    <form action="{{ route('admin.reseller-packages.store') }}" method="POST" class="ui-card p-8 space-y-6">
        @csrf

        <!-- Package Name -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package Name</label>
            <input type="text" name="name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., Starter, Professional, Enterprise" required value="{{ old('name') }}">
            @error('name')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Description -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Brief description of this package...">{{ old('description') }}</textarea>
            @error('description')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Billing Cycle -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="monthly" class="rounded border-slate-300" {{ old('billing_cycle') === 'monthly' ? 'checked' : '' }} required>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Monthly</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="annually" class="rounded border-slate-300" {{ old('billing_cycle') === 'annually' ? 'checked' : '' }}>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Annually</span>
                </label>
            </div>
            @error('billing_cycle')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Service slots & disk pool -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Max service slots</label>
                <input type="number" name="max_services" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" min="0" max="10000" value="{{ old('max_services', 0) }}">
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Optional cap on concurrent hosting services. 0 = unlimited; the resource pools below are what the package sells.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Disk pool (GB)</label>
                <input type="number" name="disk_pool_gb" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" min="1" max="100000" required value="{{ old('disk_pool_gb', 100) }}">
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Included DirectAdmin + container disk before overage billing</p>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Disk overage rate (KES/GB/month)</label>
            <input type="number" name="disk_overage_rate" step="0.01" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" min="0" value="{{ old('disk_overage_rate') }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Leave blank to use platform default</p>
        </div>

        <!-- Compute pool -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">vCPU pool</label>
                <input type="number" name="cpu_pool_cores" step="0.25" min="0" max="1024" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('cpu_pool_cores', 0) }}">
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Total vCPU across the reseller's application hosting plans. 0 = unmetered.</p>
                @error('cpu_pool_cores')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM pool (MB)</label>
                <input type="number" name="memory_pool_mb" step="256" min="0" max="4194304" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('memory_pool_mb', 0) }}">
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Shared hosting has no CPU or RAM allocation, so this covers containers only. 0 = unmetered.</p>
                @error('memory_pool_mb')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Bandwidth pool + overage rates -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Bandwidth pool (GB per month)</label>
                <input type="number" name="bandwidth_pool_gb" step="10" min="0" max="10000000" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('bandwidth_pool_gb', 0) }}">
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Transfer across the reseller's application hosting stacks each month. 0 = unmetered.</p>
            </div>
            <div class="flex items-end pb-6">
                <label class="inline-flex items-center gap-2 text-sm text-slate-900 dark:text-white">
                    <input type="hidden" name="backups_included" value="0">
                    <input type="checkbox" name="backups_included" value="1" @checked(old('backups_included', true)) class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    Backups included at no charge
                </label>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">vCPU overage (KES/vCPU/month)</label>
                <input type="number" name="cpu_overage_rate" step="0.01" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('cpu_overage_rate') }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM overage (KES/GB/month)</label>
                <input type="number" name="memory_overage_rate" step="0.01" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('memory_overage_rate') }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Bandwidth overage (KES/GB)</label>
                <input type="number" name="bandwidth_overage_rate" step="0.01" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" value="{{ old('bandwidth_overage_rate') }}">
            </div>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-400 -mt-2">Overage above a pool is billed on the package renewal invoice. Leave a rate blank to use the platform default from Settings.</p>

        <!-- Max Users -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Maximum customers</label>
            <input type="number" name="max_users" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="0 = unlimited" min="0" max="100000" value="{{ old('max_users', 0) }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Optional cap on customer accounts. 0 = unlimited.</p>
            @error('max_users')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Price -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Price (KES)</label>
            <input type="number" name="price" step="0.01" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., 5000.00" min="0" required value="{{ old('price') }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Price per billing cycle</p>
            @error('price')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Active Toggle -->
        <div>
            <label class="flex items-center gap-3">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" class="rounded border-slate-300" {{ old('active') ? 'checked' : 'checked' }}>
                <span class="text-sm font-medium text-slate-900 dark:text-white">Active</span>
            </label>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Inactive packages won't be available for purchase</p>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                Create Package
            </button>
            <a href="{{ route('admin.reseller-packages.index') }}" class="px-6 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg font-medium transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
