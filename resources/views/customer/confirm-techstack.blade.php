@extends('layouts.customer')

@section('title', 'Confirm Techstack & Choose Package')

@section('content')
@php
    $rate = (float) ($currency?->exchange_rate ?? 1);
    $symbol = $currency?->symbol ?? 'KES';
    $productCount = $products->count();
    $gridClass = match (true) {
        $productCount === 1 => 'grid-cols-1 max-w-md mx-auto',
        $productCount === 2 => 'grid-cols-1 sm:grid-cols-2 max-w-3xl mx-auto',
        $productCount === 3 => 'grid-cols-1 md:grid-cols-3',
        default => 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3',
    };
@endphp
<div
    class="space-y-8"
    x-data="packageConfigurator(@js($currencyCode), @js($symbol), {{ $rate }})"
    x-init="
        @if($products->isNotEmpty())
            @php $first = $products->first(); @endphp
            selectProduct(
                {{ $first->id }},
                {{ $first->reseller_product_id ?? 'null' }},
                @js($first->name),
                {{ (float) $first->monthly_price * $rate }},
                {{ (float) ($first->yearly_price ?? ($first->monthly_price * 12)) * $rate }}
            );
        @endif
    "
>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Choose Your Hosting Package</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Plans are listed from lowest to highest monthly price.</p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="relative shrink-0 mt-1" title="Cart">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            @if($cartCount > 0)
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    @if(!empty($attachDomain))
        <div class="rounded-xl border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/30 px-4 py-3 text-sm text-sky-900 dark:text-sky-100">
            <p class="font-semibold">Linked domain: {{ $attachDomain['fqdn'] }}</p>
            <p class="mt-0.5 text-sky-800/90 dark:text-sky-200/90">This plan will use the domain already in your cart. One invoice at checkout.</p>
        </div>
    @endif

    {{-- Stack summary --}}
    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-5 py-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Your stack</h2>
            <a href="{{ route('customer.select-techstack') }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">Change stack</a>
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-800 dark:text-slate-200">
                <span class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">App</span>
                {{ $language->name }}
            </span>
            @if(!empty($stackSelection['framework']))
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-800 dark:text-slate-200">
                    <span class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Framework</span>
                    {{ config('stack_builder.framework_labels.'.$stackSelection['framework'], $stackSelection['framework']) }}
                </span>
            @endif
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-800 dark:text-slate-200">
                <span class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Frontend</span>
                {{ config('stack_builder.frontend_labels.'.($stackSelection['frontend'] ?? 'none'), $stackSelection['frontend'] ?? 'None') }}
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-800 dark:text-slate-200">
                <span class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database</span>
                {{ $database?->name ?? 'None' }}
            </span>
        </div>
        @if(($stackSelection['frontend'] ?? null) === 'nextjs' && ($language->slug ?? '') === 'laravel')
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                Includes separate <strong class="font-semibold text-slate-900 dark:text-white">API</strong> and <strong class="font-semibold text-slate-900 dark:text-white">Web</strong> containers under one project — billed as a single plan.
            </p>
        @endif
    </div>

    {{-- Packages --}}
    <div>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Select a plan</h2>
        <div class="grid {{ $gridClass }} gap-4">
            @foreach($products as $product)
                @php
                    $limits = is_array($product->resource_limits ?? null) ? $product->resource_limits : [];
                    $cpu = $limits['cpu'] ?? null;
                    $memoryMb = $limits['memory'] ?? null;
                    $diskGb = $limits['disk'] ?? null;
                    $memoryLabel = $memoryMb !== null
                        ? ((float) $memoryMb >= 1024
                            ? rtrim(rtrim(number_format(((float) $memoryMb) / 1024, 1), '0'), '.').' GB'
                            : number_format((float) $memoryMb, 0).' MB')
                        : null;
                    $monthly = (float) $product->monthly_price * $rate;
                    $yearly = (float) ($product->yearly_price ?? ($product->monthly_price * 12)) * $rate;
                    $isFeatured = (bool) ($product->featured ?? false);
                @endphp
                <button
                    type="button"
                    @click="selectProduct({{ $product->id }}, {{ $product->reseller_product_id ?? 'null' }}, @js($product->name), {{ $monthly }}, {{ $yearly }})"
                    class="relative flex flex-col h-full text-left rounded-2xl border bg-white dark:bg-slate-900 p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950"
                    :class="selectedProductId === {{ $product->id }}
                        ? 'border-blue-600 dark:border-blue-500 ring-2 ring-blue-600/20 dark:ring-blue-400/30 shadow-md'
                        : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-sm'"
                >
                    @if($isFeatured)
                        <span class="absolute top-3 right-3 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-950/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-200">
                            Popular
                        </span>
                    @endif

                    <div class="flex items-start justify-between gap-3 mb-4 pr-12">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white leading-snug">{{ $product->name }}</h3>
                            @if($product->description)
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400 line-clamp-2">{{ $product->description }}</p>
                            @endif
                        </div>
                        <span
                            class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2"
                            :class="selectedProductId === {{ $product->id }}
                                ? 'border-blue-600 bg-blue-600 text-white'
                                : 'border-slate-300 dark:border-slate-600'"
                            aria-hidden="true"
                        >
                            <svg x-show="selectedProductId === {{ $product->id }}" class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>

                    <div class="mb-5">
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $symbol }}{{ number_format($monthly, 0) }}</span>
                            <span class="text-sm text-slate-500 dark:text-slate-400">/mo</span>
                        </div>
                        @if($product->yearly_price)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                or {{ $symbol }}{{ number_format($yearly, 0) }}/year
                            </p>
                        @endif
                    </div>

                    @if($cpu !== null || $memoryLabel !== null || $diskGb !== null)
                        <div class="grid grid-cols-3 gap-2 mb-5 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                            <div class="text-center">
                                <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $cpu !== null ? rtrim(rtrim(number_format((float) $cpu, 2), '0'), '.') : '—' }}
                                </p>
                            </div>
                            <div class="text-center border-x border-slate-200 dark:border-slate-700">
                                <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">RAM</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">{{ $memoryLabel ?? '—' }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[10px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $diskGb !== null ? rtrim(rtrim(number_format((float) $diskGb, 1), '0'), '.').' GB' : '—' }}
                                </p>
                            </div>
                        </div>
                    @endif

                    @if(!empty($product->features) && count($product->features) > 0)
                        <ul class="space-y-2 mb-5 flex-1">
                            @foreach(array_slice($product->features, 0, 4) as $feature)
                                <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="flex-1"></div>
                    @endif

                    <div
                        class="mt-auto pt-4 border-t border-slate-100 dark:border-slate-800 text-sm font-semibold"
                        :class="selectedProductId === {{ $product->id }} ? 'text-blue-600 dark:text-blue-400' : 'text-slate-500 dark:text-slate-400'"
                        x-text="selectedProductId === {{ $product->id }} ? 'Selected' : 'Select plan'"
                    ></div>
                </button>
            @endforeach
        </div>
    </div>

    <template x-if="selectedProductId">
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 md:p-8">
            <div class="flex flex-wrap items-end justify-between gap-2 mb-6">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Finalize your order</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Plan: <span class="font-medium text-slate-800 dark:text-slate-200" x-text="selectedProductName"></span>
                    </p>
                </div>
            </div>

            <form action="{{ route('customer.cart.add') }}" method="POST" class="space-y-5" x-init="updatePrice()">
                @csrf
                <input type="hidden" name="type" value="{{ $isResellerCustomer ? 'reseller_product' : 'product' }}">
                @if ($isResellerCustomer)
                    <input type="hidden" name="reseller_product_id" :value="selectedResellerProductId">
                @else
                    <input type="hidden" name="product_id" :value="selectedProductId">
                @endif
                <input type="hidden" name="billing_cycle" x-bind:value="cycle">
                @if($language->versions && count($language->versions) > 0)
                    <input type="hidden" name="version" x-bind:value="version">
                @endif

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @if($language->versions && count($language->versions) > 0)
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">{{ $language->name }} version</label>
                            <select x-model="version" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                                @foreach($language->versions as $version)
                                    <option value="{{ $version }}">v{{ $version }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing cycle</label>
                        <select x-model="cycle" @change="updatePrice()" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div class="rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Due now</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1" x-text="currencySymbol + ' ' + formatPrice(calculatedPrice)"></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="getPricingLabel()"></p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    <button type="submit" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                        Add to cart
                    </button>
                    <a href="{{ route('customer.select-techstack') }}" class="px-6 py-3 text-center border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Change stack
                    </a>
                </div>
            </form>
        </div>
    </template>
</div>

<script>
function packageConfigurator(currencyCode, currencySymbol, exchangeRate) {
    return {
        currencyCode: currencyCode,
        currencySymbol: currencySymbol,
        exchangeRate: exchangeRate,
        selectedProductId: null,
        selectedResellerProductId: null,
        selectedProductName: '',
        selectedProductPrice: 0,
        cycle: 'monthly',
        version: @js($language->versions[0] ?? ''),
        basePrice: 0,
        yearlyPrice: 0,
        calculatedPrice: 0,
        billingMonths: 1,

        selectProduct(productId, resellerProductId, productName, productPrice, yearlyPrice) {
            this.selectedProductId = productId;
            this.selectedResellerProductId = resellerProductId;
            this.selectedProductName = productName;
            this.selectedProductPrice = productPrice;
            this.basePrice = productPrice;
            this.yearlyPrice = yearlyPrice;
            this.updatePrice();
        },

        updatePrice() {
            const cycles = {
                monthly: { months: 1 },
                quarterly: { months: 3 },
                'semi-annual': { months: 6 },
                annual: { months: 12 }
            };
            const config = cycles[this.cycle] || cycles.monthly;
            this.billingMonths = config.months;
            this.calculatedPrice = this.cycle === 'annual'
                ? this.yearlyPrice
                : this.basePrice * config.months;
        },

        formatPrice(amount) {
            return Math.round(amount).toLocaleString('en-US');
        },

        getPricingLabel() {
            return {
                monthly: 'Per month',
                quarterly: 'Per 3 months',
                'semi-annual': 'Per 6 months',
                annual: 'Per year'
            }[this.cycle] || 'Per month';
        }
    };
}
</script>
@endsection
