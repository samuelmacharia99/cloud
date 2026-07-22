@php
    $limits = $limits ?? [];
@endphp

<div class="border-t border-slate-200 dark:border-slate-800 pt-6" x-show="productType === 'container_hosting'" x-cloak>
    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Package Resource Limits</h3>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Define the CPU, memory, and disk included with this application hosting package.</p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div>
            <label for="container_cpu" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">CPU Cores</label>
            <input
                type="number"
                id="container_cpu"
                name="resource_limits[cpu]"
                value="{{ old('resource_limits.cpu', $limits['cpu'] ?? '') }}"
                step="0.1"
                min="0"
                placeholder="e.g. 2"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('resource_limits.cpu') border-red-500 @enderror"
            >
            @error('resource_limits.cpu')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="container_memory" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Memory (MB)</label>
            <input
                type="number"
                id="container_memory"
                name="resource_limits[memory]"
                value="{{ old('resource_limits.memory', $limits['memory'] ?? '') }}"
                step="1"
                min="0"
                placeholder="e.g. 2048"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('resource_limits.memory') border-red-500 @enderror"
            >
            @error('resource_limits.memory')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="container_disk" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Disk (GB)</label>
            <input
                type="number"
                id="container_disk"
                name="resource_limits[disk]"
                value="{{ old('resource_limits.disk', $limits['disk'] ?? '') }}"
                step="1"
                min="0"
                placeholder="e.g. 50"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('resource_limits.disk') border-red-500 @enderror"
            >
            @error('resource_limits.disk')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
