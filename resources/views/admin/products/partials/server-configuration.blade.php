@php
    use App\Services\ServerProductConfigService;

    $configService = app(ServerProductConfigService::class);
    $limits = $limits ?? [];
    $config = $configService->config(new \App\Models\Product(['resource_limits' => $limits, 'type' => $productType ?? 'vps']));

    if (old('resource_limits')) {
        $oldLimits = old('resource_limits');
        if (is_array($oldLimits)) {
            $config = array_merge($config, $oldLimits);
        }
    }

    $locations = $config['locations'] ?? [];
    if ($locations === [] && ! empty($config['legacy_location'])) {
        $locations = [[
            'key' => 'default',
            'name' => $config['legacy_location'],
            'city' => '',
            'monthly_surcharge' => 0,
            'yearly_surcharge' => 0,
            'wholesale_monthly_surcharge' => 0,
            'wholesale_yearly_surcharge' => 0,
            'setup_surcharge' => 0,
        ]];
    }

    $initialLocations = collect($locations)->map(function ($location) {
        return [
            'key' => $location['key'] ?? '',
            'name' => $location['name'] ?? '',
            'city' => $location['city'] ?? '',
            'monthly_surcharge' => $location['monthly_surcharge'] ?? $location['monthly_price'] ?? '',
            'yearly_surcharge' => $location['yearly_surcharge'] ?? $location['yearly_price'] ?? '',
            'wholesale_monthly_surcharge' => $location['wholesale_monthly_surcharge'] ?? $location['wholesale_monthly_price'] ?? '',
            'wholesale_yearly_surcharge' => $location['wholesale_yearly_surcharge'] ?? $location['wholesale_yearly_price'] ?? '',
            'setup_surcharge' => $location['setup_surcharge'] ?? $location['setup_fee'] ?? '',
        ];
    })->values()->all();

    if ($initialLocations === []) {
        $initialLocations = [[
            'key' => '',
            'name' => '',
            'city' => '',
            'monthly_surcharge' => '',
            'yearly_surcharge' => '',
            'wholesale_monthly_surcharge' => '',
            'wholesale_yearly_surcharge' => '',
            'setup_surcharge' => '',
        ]];
    }
@endphp

<div x-show="productType === 'vps' || productType === 'dedicated_server'" x-cloak>
<div
    class="border-t border-slate-200 dark:border-slate-800 pt-6"
    x-data="serverProductConfig(@js($initialLocations))"
>
    <div class="space-y-4 mb-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Server Configuration</h3>
        <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-2">
            <p class="text-sm text-blue-900 dark:text-blue-300">Use the product <strong>description</strong> above as a short slogan only. Enter hardware specs below.</p>
            <p class="text-sm text-blue-900 dark:text-blue-300">Set the <strong>base retail and wholesale prices</strong> in the main pricing fields above. Each datacenter row below adds a surcharge on top of that base (use <strong>0</strong> for the default/cheapest location).</p>
            <p class="text-sm text-blue-900 dark:text-blue-300">Every plan includes <strong>1 IP address</strong>. Set the price for each additional IP below.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">CPU Cores</label>
            <input type="number" name="resource_limits[cpu_cores]" value="{{ old('resource_limits.cpu_cores', $config['cpu_cores'] ?? '') }}" min="1" step="1" placeholder="e.g. 2" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAM (GB)</label>
            <input type="number" name="resource_limits[ram_gb]" value="{{ old('resource_limits.ram_gb', $config['ram_gb'] ?? '') }}" min="1" step="1" placeholder="e.g. 4" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage (GB)</label>
            <input type="number" name="resource_limits[storage_gb]" value="{{ old('resource_limits.storage_gb', $config['storage_gb'] ?? '') }}" min="1" step="1" placeholder="e.g. 80" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage Type</label>
            <select name="resource_limits[storage_type]" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                <option value="">Select…</option>
                @foreach (['NVMe', 'SSD', 'HDD'] as $storageType)
                    <option value="{{ $storageType }}" @selected(strtoupper((string) old('resource_limits.storage_type', $config['storage_type'] ?? '')) === $storageType)>{{ $storageType }}</option>
                @endforeach
            </select>
        </div>
        <div x-show="productType === 'dedicated_server'">
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">RAID</label>
            <input type="text" name="resource_limits[raid]" value="{{ old('resource_limits.raid', $config['raid'] ?? '') }}" placeholder="e.g. RAID-10" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Bandwidth (TB)</label>
            <input type="number" name="resource_limits[bandwidth_tb]" value="{{ old('resource_limits.bandwidth_tb', $config['bandwidth_tb'] ?? '') }}" min="0" step="0.1" placeholder="e.g. 10" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Additional IP price (monthly)</label>
            <input type="number" name="resource_limits[additional_ip_monthly]" value="{{ old('resource_limits.additional_ip_monthly', $config['additional_ip_monthly'] ?? '') }}" min="0" step="0.01" placeholder="e.g. 200" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Per extra IP beyond the included 1</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Additional IP setup fee</label>
            <input type="number" name="resource_limits[additional_ip_setup]" value="{{ old('resource_limits.additional_ip_setup', $config['additional_ip_setup'] ?? '') }}" min="0" step="0.01" placeholder="e.g. 50" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Money-back (days)</label>
            <input type="number" name="resource_limits[money_back_days]" value="{{ old('resource_limits.money_back_days', $config['money_back_days'] ?? 30) }}" min="0" step="1" placeholder="30" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-3 cursor-pointer pb-2">
                <input type="hidden" name="resource_limits[managed]" value="0">
                <input type="checkbox" name="resource_limits[managed]" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(filter_var(old('resource_limits.managed', $config['managed'] ?? false), FILTER_VALIDATE_BOOLEAN))>
                <span class="text-sm text-slate-700 dark:text-slate-300">Fully managed hosting</span>
            </label>
        </div>
    </div>

    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-base font-semibold text-slate-900 dark:text-white">Datacenter Surcharges</h4>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Added on top of the base prices above. Example: base KES 1,000 + USA surcharge KES 300 = KES 1,300/mo in USA.</p>
            </div>
            <button type="button" @click="addLocation()" class="px-3 py-1.5 text-sm font-medium text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-950/60 transition">
                + Add location
            </button>
        </div>

        <template x-for="(location, locIndex) in locations" :key="locIndex">
            <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-5 bg-slate-50/50 dark:bg-slate-800/30 space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white" x-text="'Location ' + (locIndex + 1)"></p>
                    <button type="button" x-show="locations.length > 1" @click="removeLocation(locIndex)" class="text-xs text-red-600 dark:text-red-400 hover:underline">Remove</button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Location name *</label>
                        <input type="text" :name="'resource_limits[locations][' + locIndex + '][name]'" x-model="location.name" placeholder="USA — East" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">City / region</label>
                        <input type="text" :name="'resource_limits[locations][' + locIndex + '][city]'" x-model="location.city" placeholder="New York" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Key (slug)</label>
                        <input type="text" :name="'resource_limits[locations][' + locIndex + '][key]'" x-model="location.key" placeholder="usa-east" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Monthly surcharge</label>
                        <input type="number" :name="'resource_limits[locations][' + locIndex + '][monthly_surcharge]'" x-model="location.monthly_surcharge" step="0.01" min="0" placeholder="0" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Yearly surcharge</label>
                        <input type="number" :name="'resource_limits[locations][' + locIndex + '][yearly_surcharge]'" x-model="location.yearly_surcharge" step="0.01" min="0" placeholder="0" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Setup surcharge</label>
                        <input type="number" :name="'resource_limits[locations][' + locIndex + '][setup_surcharge]'" x-model="location.setup_surcharge" step="0.01" min="0" placeholder="0" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Wholesale monthly surcharge</label>
                        <input type="number" :name="'resource_limits[locations][' + locIndex + '][wholesale_monthly_surcharge]'" x-model="location.wholesale_monthly_surcharge" step="0.01" min="0" placeholder="0" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Wholesale yearly surcharge</label>
                        <input type="number" :name="'resource_limits[locations][' + locIndex + '][wholesale_yearly_surcharge]'" x-model="location.wholesale_yearly_surcharge" step="0.01" min="0" placeholder="0" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg">
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
</div>

@once
    @push('scripts')
    <script>
        function serverProductConfig(initialLocations) {
            return {
                locations: initialLocations.length ? initialLocations : [{
                    key: '',
                    name: '',
                    city: '',
                    monthly_surcharge: '',
                    yearly_surcharge: '',
                    wholesale_monthly_surcharge: '',
                    wholesale_yearly_surcharge: '',
                    setup_surcharge: '',
                }],
                addLocation() {
                    this.locations.push({
                        key: '',
                        name: '',
                        city: '',
                        monthly_surcharge: '',
                        yearly_surcharge: '',
                        wholesale_monthly_surcharge: '',
                        wholesale_yearly_surcharge: '',
                        setup_surcharge: '',
                    });
                },
                removeLocation(index) {
                    this.locations.splice(index, 1);
                },
            };
        }
    </script>
    @endpush
@endonce
