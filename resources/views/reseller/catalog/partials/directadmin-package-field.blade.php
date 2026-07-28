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
            Link this plan to a hosting package from your DirectAdmin reseller panel (not the platform admin catalog). That package is used when customer hosting is auto-provisioned.
        </p>
    </div>

    @if (! $directAdminBinding)
        <p class="text-sm text-amber-700 dark:text-amber-300">
            Your account is not linked to DirectAdmin yet. Ask your provider to link your DirectAdmin reseller account from the admin reseller profile.
        </p>
    @elseif ($directAdminPackagesError && empty($directAdminPackages))
        <p class="text-sm text-amber-700 dark:text-amber-300">{{ $directAdminPackagesError }}</p>
    @elseif (! empty($directAdminPackages))
        @php
            $selected = old('direct_admin_package_name', $selectedPackage);
            $packageNames = collect($directAdminPackages)->pluck('name')->all();
            $orphanSelected = filled($selected) && ! in_array($selected, $packageNames, true);
        @endphp
        <select
            id="direct_admin_package_name"
            name="direct_admin_package_name"
            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('direct_admin_package_name') border-red-500 @enderror"
        >
            <option value="">Select a package...</option>
            @if ($orphanSelected)
                <option value="{{ $selected }}" selected>{{ $selected }} (no longer on DirectAdmin)</option>
            @endif
            @foreach ($directAdminPackages as $package)
                <option value="{{ $package['name'] }}" @selected(! $orphanSelected && $selected === $package['name'])>
                    {{ $package['name'] }}
                    @if (($package['disk_quota'] ?? 0) > 0)
                        — {{ $package['disk_quota'] }}GB disk
                    @endif
                </option>
            @endforeach
        </select>
        @if ($orphanSelected)
            <p class="text-xs text-amber-700 dark:text-amber-300">
                Saved package <span class="font-mono">{{ $selected }}</span> is missing from DirectAdmin. Choose a current package or provisioning may fail.
            </p>
        @endif
        @error('direct_admin_package_name')
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    @elseif ($directAdminBinding && filled(old('direct_admin_package_name', $selectedPackage)))
        <input type="hidden" name="direct_admin_package_name" value="{{ old('direct_admin_package_name', $selectedPackage) }}">
        <p class="text-sm text-amber-700 dark:text-amber-300">
            No packages were returned from DirectAdmin, but this plan is mapped to
            <span class="font-mono">{{ old('direct_admin_package_name', $selectedPackage) }}</span>.
            Refresh connection or check your DirectAdmin reseller packages.
        </p>
    @endif
</div>
