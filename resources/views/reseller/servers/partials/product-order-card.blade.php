@php
    use App\Services\ServerProductConfigService;

    $configService = app(ServerProductConfigService::class);
    $specLines = $configService->specLines($product);
    $locations = $configService->locations($product);
    $defaultLocation = $locations[0] ?? null;
    $slogan = trim(strip_tags((string) ($product->description ?? '')));
    $wholesaleMonthly = (float) ($defaultLocation['wholesale_monthly_price'] ?? $product->wholesale_monthly_price ?? 0);
    $wholesaleYearly = (float) ($defaultLocation['wholesale_yearly_price'] ?? $product->wholesale_yearly_price ?? 0);
    $setupFee = (float) ($defaultLocation['setup_fee'] ?? $product->setup_fee ?? 0);
@endphp

<div class="border border-slate-200 dark:border-slate-700 rounded-xl p-5 hover:border-purple-400 transition">
    <div class="flex items-start justify-between gap-4 mb-3">
        <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
                <h4 class="text-lg font-bold text-slate-900 dark:text-white">{{ $product->name }}</h4>
                @if ($product->featured)
                    <span class="px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">Popular</span>
                @endif
            </div>
            @if ($slogan !== '')
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">{{ $slogan }}</p>
            @endif
            @if ($specLines !== [])
                <ul class="space-y-1 mb-2">
                    @foreach ($specLines as $line)
                        <li class="text-xs text-slate-700 dark:text-slate-300">• {{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        @if ($setupFee > 0)
            <p class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">Setup: {{ $currencyCode }} {{ number_format($setupFee, 0) }}</p>
        @endif
    </div>

    <form action="{{ route('reseller.servers.order') }}" method="POST" class="space-y-3 pt-3 border-t border-slate-100 dark:border-slate-800">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}">

        @if (count($locations) > 1)
            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Datacenter</label>
                <select name="location_key" required class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    @foreach ($locations as $location)
                        <option value="{{ $location['key'] }}">
                            {{ $location['name'] }}{{ ! empty($location['city']) ? ' ('.$location['city'].')' : '' }}
                            — {{ $currencyCode }} {{ number_format($location['wholesale_monthly_price'] ?? 0, 0) }}/mo
                        </option>
                    @endforeach
                </select>
            </div>
        @elseif ($defaultLocation)
            <input type="hidden" name="location_key" value="{{ $defaultLocation['key'] }}">
        @endif

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Operating System <span class="text-red-500">*</span></label>
                <select name="operating_system" required class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <option value="">Select OS...</option>
                    @foreach ($linuxDistros as $osKey => $osLabel)
                        <option value="{{ $osKey }}">{{ $osLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">IP Addresses</label>
                <select name="ip_count" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    @php $ipOptions = $defaultLocation ? $configService->ipOptionsForLocation($defaultLocation, $product) : []; @endphp
                    @forelse ($ipOptions as $option)
                        <option value="{{ $option['ips'] }}">{{ $option['label'] }}</option>
                    @empty
                        @for ($i = 1; $i <= $maxIpCount; $i++)
                            <option value="{{ $i }}">{{ $i }} {{ $i === 1 ? 'IP' : 'IPs' }}</option>
                        @endfor
                    @endforelse
                </select>
            </div>
        </div>

        <div class="flex gap-2 pt-1">
            <button type="submit" name="billing_cycle" value="monthly" class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                <span class="block text-xs opacity-80">Wholesale Monthly</span>
                <span class="font-bold">{{ $currencyCode }} {{ number_format($wholesaleMonthly, 0) }}/mo</span>
            </button>
            @if ($wholesaleYearly > 0)
                <button type="submit" name="billing_cycle" value="annual" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                    <span class="block text-xs opacity-80">Wholesale Annual — Save!</span>
                    <span class="font-bold">{{ $currencyCode }} {{ number_format($wholesaleYearly, 0) }}/yr</span>
                </button>
            @endif
        </div>
    </form>
</div>
