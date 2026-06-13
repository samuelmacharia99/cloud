@php
    use App\Services\ServerProductConfigService;

    $listing = isset($resellerListings) ? $resellerListings->get($product->id) : null;
    $configService = app(ServerProductConfigService::class);
    $specLines = $configService->specLines($product);
    $locations = $configService->locations($product);
    $isDedicated = ($product->type ?? '') === 'dedicated_server';

    $locationPayload = collect($locations)->map(function ($location) use ($configService, $product, $listing) {
        $resolved = $configService->resolvedLocationPrices($product, $location, $listing, false);

        return [
            'key' => $location['key'] ?? '',
            'name' => $location['name'] ?? '',
            'city' => $location['city'] ?? '',
            'monthly_price' => $resolved['monthly'],
            'yearly_price' => $resolved['yearly'],
            'setup_fee' => $resolved['setup'],
            'ip_options' => $configService->ipOptionsForLocation($location, $product),
        ];
    })->values()->all();

    $defaultLocation = $locationPayload[0] ?? null;
    $displayMonthly = (float) ($defaultLocation['monthly_price'] ?? $product->monthly_price);
    $displayYearly = (float) ($defaultLocation['yearly_price'] ?? $product->yearly_price);
    $displaySetup = (float) ($defaultLocation['setup_fee'] ?? $product->setup_fee);
    $descriptionHtml = format_product_description($product->description ?? '');
@endphp

<article
    @class([
        'group flex flex-col h-full bg-white dark:bg-slate-900 rounded-xl border overflow-hidden shadow-sm transition-all duration-200',
        'border-slate-200 dark:border-slate-800 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-700' => $isDedicated,
        'border-slate-200 dark:border-slate-800 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-700' => ! $isDedicated,
    ])
    x-data="serverPlanCard({
        locations: @js($locationPayload),
        currencySymbol: @js($currencySymbol),
    })"
>
    @if ($product->featured)
        <div class="px-4 py-1.5 bg-amber-50 dark:bg-amber-950/40 border-b border-amber-100 dark:border-amber-900/50">
            <p class="text-xs font-semibold text-amber-700 dark:text-amber-300 tracking-wide uppercase">Most popular</p>
        </div>
    @endif

    <div class="p-5 flex flex-col flex-1">
        <div class="mb-4">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                {{ App\Models\Product::typeLabel($product->type) }}
            </p>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white leading-tight">{{ $product->name }}</h3>
            @if ($descriptionHtml !== '')
                <div class="text-sm text-slate-600 dark:text-slate-400 mt-2 line-clamp-3">{!! $descriptionHtml !!}</div>
            @endif
        </div>

        @if ($specLines !== [])
            <ul class="space-y-2 mb-5">
                @foreach ($specLines as $line)
                    <li class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <svg @class(['w-4 h-4 mt-0.5 shrink-0', 'text-indigo-600 dark:text-indigo-400' => $isDedicated, 'text-blue-600 dark:text-blue-400' => ! $isDedicated]) fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ $line }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-auto pt-4 border-t border-slate-100 dark:border-slate-800">
            <div class="flex items-baseline gap-1 mb-1">
                <span class="text-2xl font-bold text-slate-900 dark:text-white" x-text="currencySymbol + formatPrice(monthlyPrice)"></span>
                <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
            </div>
            <template x-if="yearlyPrice > 0">
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mb-1" x-text="'or ' + currencySymbol + formatPrice(yearlyPrice) + '/year'"></p>
            </template>
            <template x-if="setupFee > 0">
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4" x-text="'Setup fee: ' + currencySymbol + formatPrice(setupFee)"></p>
            </template>
            <template x-if="setupFee <= 0">
                <div class="mb-4"></div>
            </template>

            <form action="{{ route('customer.servers.order') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="location_key" :value="selectedLocationKey">

                @if (count($locationPayload) > 1)
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Datacenter</label>
                        <select x-model="selectedLocationKey" @change="onLocationChange()" @class([
                            'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-slate-900 dark:text-white focus:border-transparent',
                            'focus:ring-2 focus:ring-indigo-500' => $isDedicated,
                            'focus:ring-2 focus:ring-blue-500' => ! $isDedicated,
                        ])>
                            <template x-for="location in locations" :key="location.key">
                                <option :value="location.key" x-text="locationLabel(location)"></option>
                            </template>
                        </select>
                    </div>
                @elseif (count($locationPayload) === 1)
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <span class="font-medium text-slate-700 dark:text-slate-300">Location:</span>
                        {{ $locationPayload[0]['name'] }}{{ $locationPayload[0]['city'] ? ', '.$locationPayload[0]['city'] : '' }}
                    </p>
                @endif

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Operating system</label>
                    <select name="operating_system" required @class([
                        'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-slate-900 dark:text-white focus:border-transparent',
                        'focus:ring-2 focus:ring-indigo-500' => $isDedicated,
                        'focus:ring-2 focus:ring-blue-500' => ! $isDedicated,
                    ])>
                        <option value="">Select OS…</option>
                        @foreach ($linuxDistros as $osKey => $osLabel)
                            <option value="{{ $osKey }}">{{ $osLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">IP addresses</label>
                    <select name="ip_count" x-model="selectedIpCount" @change="recalculate()" @class([
                        'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-slate-900 dark:text-white focus:border-transparent',
                        'focus:ring-2 focus:ring-indigo-500' => $isDedicated,
                        'focus:ring-2 focus:ring-blue-500' => ! $isDedicated,
                    ])>
                        <template x-for="option in ipOptions" :key="option.ips">
                            <option :value="option.ips" x-text="option.label"></option>
                        </template>
                    </select>
                </div>

                <div class="grid grid-cols-1 gap-2 pt-1">
                    <button type="submit" name="billing_cycle" value="monthly" @class([
                        'w-full py-2.5 px-4 text-white text-sm font-semibold rounded-lg transition',
                        'bg-indigo-600 hover:bg-indigo-700' => $isDedicated,
                        'bg-blue-600 hover:bg-blue-700' => ! $isDedicated,
                    ])>
                        Order monthly
                    </button>
                    <template x-if="yearlyPrice > 0">
                        <button type="submit" name="billing_cycle" value="annual" class="w-full py-2.5 px-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-200 hover:border-emerald-500 dark:hover:border-emerald-600 text-sm font-semibold rounded-lg transition">
                            Order annually — save
                        </button>
                    </template>
                </div>
            </form>
        </div>
    </div>
</article>

@once
    @push('scripts')
    <script>
        function serverPlanCard(config) {
            const firstLocation = config.locations[0] ?? null;

            return {
                locations: config.locations,
                currencySymbol: config.currencySymbol,
                selectedLocationKey: firstLocation?.key ?? '',
                selectedIpCount: firstLocation?.ip_options?.[0]?.ips ?? 1,
                ipOptions: firstLocation?.ip_options ?? [],
                monthlyPrice: firstLocation?.monthly_price ?? 0,
                yearlyPrice: firstLocation?.yearly_price ?? 0,
                setupFee: firstLocation?.setup_fee ?? 0,
                init() {
                    this.recalculate();
                },
                locationLabel(location) {
                    return location.city ? `${location.name} (${location.city})` : location.name;
                },
                currentLocation() {
                    return this.locations.find((location) => location.key === this.selectedLocationKey) ?? this.locations[0] ?? null;
                },
                onLocationChange() {
                    const location = this.currentLocation();
                    this.ipOptions = location?.ip_options ?? [];
                    this.selectedIpCount = this.ipOptions[0]?.ips ?? 1;
                    this.recalculate();
                },
                recalculate() {
                    const location = this.currentLocation();
                    const tier = this.ipOptions.find((option) => Number(option.ips) === Number(this.selectedIpCount))
                        ?? this.ipOptions[0]
                        ?? { monthly_addon: 0, setup_addon: 0 };

                    this.monthlyPrice = Number(location?.monthly_price ?? 0) + Number(tier.monthly_addon ?? 0);
                    this.yearlyPrice = Number(location?.yearly_price ?? 0) + (Number(tier.monthly_addon ?? 0) * 12);
                    this.setupFee = Number(location?.setup_fee ?? 0) + Number(tier.setup_addon ?? 0);
                },
                formatPrice(value) {
                    return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
                },
            };
        }
    </script>
    @endpush
@endonce
