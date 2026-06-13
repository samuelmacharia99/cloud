@php
    $listing = isset($resellerListings) ? $resellerListings->get($product->id) : null;
    $displayMonthly = $listing?->monthly_price ?? $product->monthly_price;
    $displayYearly = $listing?->yearly_price ?? $product->yearly_price;
    $displaySetup = $listing?->setup_fee ?? $product->setup_fee;
    $limits = $product->resource_limits ?? [];
    $isDedicated = ($product->type ?? '') === 'dedicated_server';
@endphp

<article @class([
    'group flex flex-col h-full bg-white dark:bg-slate-900 rounded-xl border overflow-hidden shadow-sm transition-all duration-200',
    'border-slate-200 dark:border-slate-800 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-700' => $isDedicated,
    'border-slate-200 dark:border-slate-800 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-700' => ! $isDedicated,
])>
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
            @if($product->description)
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 line-clamp-2">{{ $product->description }}</p>
            @endif
        </div>

        @if(($limits['specs'] ?? null) || ($limits['location'] ?? null))
            <ul class="space-y-2 mb-5">
                @if($limits['specs'] ?? null)
                    <li class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <svg @class(['w-4 h-4 mt-0.5 shrink-0', 'text-indigo-600 dark:text-indigo-400' => $isDedicated, 'text-blue-600 dark:text-blue-400' => ! $isDedicated]) fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ $limits['specs'] }}</span>
                    </li>
                @endif
                @if($limits['location'] ?? null)
                    <li class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <svg @class(['w-4 h-4 mt-0.5 shrink-0', 'text-indigo-600 dark:text-indigo-400' => $isDedicated, 'text-blue-600 dark:text-blue-400' => ! $isDedicated]) fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span>{{ $limits['location'] }}</span>
                    </li>
                @endif
            </ul>
        @endif

        <div class="mt-auto pt-4 border-t border-slate-100 dark:border-slate-800">
            <div class="flex items-baseline gap-1 mb-1">
                <span class="text-2xl font-bold text-slate-900 dark:text-white">{{ $currencySymbol }}{{ number_format($displayMonthly, 0) }}</span>
                <span class="text-sm text-slate-500 dark:text-slate-400">/month</span>
            </div>
            @if ($displayYearly)
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mb-1">
                    or {{ $currencySymbol }}{{ number_format($displayYearly, 0) }}/year
                </p>
            @endif
            @if ($displaySetup > 0)
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Setup fee: {{ $currencySymbol }}{{ number_format($displaySetup, 0) }}</p>
            @else
                <div class="mb-4"></div>
            @endif

            <form action="{{ route('customer.servers.order') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Operating system</label>
                    <select name="operating_system" required @class([
                        'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-slate-900 dark:text-white focus:border-transparent',
                        'focus:ring-2 focus:ring-indigo-500' => $isDedicated,
                        'focus:ring-2 focus:ring-blue-500' => ! $isDedicated,
                    ])>
                        <option value="">Select OS…</option>
                        @foreach($linuxDistros as $osKey => $osLabel)
                            <option value="{{ $osKey }}">{{ $osLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">IP addresses</label>
                    <select name="ip_count" @class([
                        'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 rounded-lg text-slate-900 dark:text-white focus:border-transparent',
                        'focus:ring-2 focus:ring-indigo-500' => $isDedicated,
                        'focus:ring-2 focus:ring-blue-500' => ! $isDedicated,
                    ])>
                        @for($i = 1; $i <= $maxIpCount; $i++)
                            <option value="{{ $i }}">{{ $i }} {{ $i === 1 ? 'IP' : 'IPs' }}</option>
                        @endfor
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
                    @if ($displayYearly)
                        <button type="submit" name="billing_cycle" value="annual" class="w-full py-2.5 px-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-200 hover:border-emerald-500 dark:hover:border-emerald-600 text-sm font-semibold rounded-lg transition">
                            Order annually — save
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</article>
