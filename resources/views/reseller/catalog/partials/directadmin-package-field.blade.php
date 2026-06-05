@props([
    'directAdminBinding' => false,
    'directAdminPackages' => [],
    'directAdminPackagesError' => null,
    'selectedPackage' => null,
])

<div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4 space-y-3">
    <div>
        <label for="direct_admin_package_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">
            DirectAdmin package
        </label>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Link this plan to a package on your DirectAdmin reseller account. That package is used when customer hosting is auto-provisioned.
        </p>
    </div>

    @if (! $directAdminBinding)
        <p class="text-sm text-amber-700 dark:text-amber-300">
            Your account is not linked to DirectAdmin yet. Ask your provider to set your DirectAdmin username and server under reseller settings.
        </p>
    @elseif ($directAdminPackagesError && empty($directAdminPackages))
        <p class="text-sm text-amber-700 dark:text-amber-300">{{ $directAdminPackagesError }}</p>
    @elseif (! empty($directAdminPackages))
        <select
            id="direct_admin_package_name"
            name="direct_admin_package_name"
            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('direct_admin_package_name') border-red-500 @enderror"
        >
            <option value="">Select a package...</option>
            @foreach ($directAdminPackages as $package)
                <option value="{{ $package['name'] }}" @selected(old('direct_admin_package_name', $selectedPackage) === $package['name'])>
                    {{ $package['name'] }}
                    @if (($package['disk_quota'] ?? 0) > 0)
                        — {{ $package['disk_quota'] }}GB disk
                    @endif
                </option>
            @endforeach
        </select>
        @error('direct_admin_package_name')
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    @endif
</div>
