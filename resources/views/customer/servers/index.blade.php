@extends('layouts.customer')

@section('title', 'Servers')

@section('content')
@php
    $catalogProducts = match ($selectedType) {
        'vps' => $vpsProducts,
        'dedicated_server' => $dedicatedProducts,
        default => collect(),
    };
    $typeLabel = $selectedType ? App\Models\Product::typeLabel($selectedType) : null;
@endphp

<div class="space-y-8">
    {{-- Page header --}}
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-1">Infrastructure</p>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Servers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-2 max-w-2xl">
                @if($selectedType)
                    Compare {{ strtolower($typeLabel) }} plans and deploy in minutes. Full root access, flexible billing, enterprise-grade hardware.
                @elseif($services->count() > 0)
                    Manage your active servers or deploy additional capacity from our catalog.
                @else
                    Deploy virtual or dedicated servers with transparent pricing and instant provisioning.
                @endif
            </p>
        </div>
        @if($services->count() > 0)
            <div class="flex items-center gap-3 px-4 py-3 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200 dark:border-slate-700">
                <div class="text-right">
                    <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">Active servers</p>
                    <p class="text-xl font-bold text-slate-900 dark:text-white">{{ $services->count() }}</p>
                </div>
            </div>
        @endif
    </div>

    {{-- Tab navigation --}}
    <nav class="border-b border-slate-200 dark:border-slate-800" aria-label="Server sections">
        <div class="flex flex-wrap gap-1 -mb-px">
            <a href="{{ route('customer.servers.index') }}"
               @class([
                   'inline-flex items-center gap-2 px-4 py-3 text-sm font-semibold border-b-2 transition-colors',
                   'border-blue-600 text-blue-600 dark:text-blue-400' => ! $selectedType,
                   'border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:border-slate-300 dark:hover:border-slate-600' => $selectedType,
               ])>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                My servers
            </a>
            <a href="{{ route('customer.servers.index', ['type' => 'vps']) }}"
               @class([
                   'inline-flex items-center gap-2 px-4 py-3 text-sm font-semibold border-b-2 transition-colors',
                   'border-blue-600 text-blue-600 dark:text-blue-400' => $selectedType === 'vps',
                   'border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:border-slate-300 dark:hover:border-slate-600' => $selectedType !== 'vps',
               ])>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                VPS plans
            </a>
            <a href="{{ route('customer.servers.index', ['type' => 'dedicated_server']) }}"
               @class([
                   'inline-flex items-center gap-2 px-4 py-3 text-sm font-semibold border-b-2 transition-colors',
                   'border-indigo-600 text-indigo-600 dark:text-indigo-400' => $selectedType === 'dedicated_server',
                   'border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:border-slate-300 dark:hover:border-slate-600' => $selectedType !== 'dedicated_server',
               ])>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                Dedicated plans
            </a>
        </div>
    </nav>

    {{-- Catalog grid (when browsing a plan type) --}}
    @if($selectedType)
        <section>
            <div class="flex items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Available {{ $typeLabel }} plans</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Select a configuration, choose your OS, and add to cart.</p>
                </div>
                <span class="hidden sm:inline-flex text-xs font-medium text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-3 py-1.5 rounded-full">
                    {{ $catalogProducts->count() }} {{ Str::plural('plan', $catalogProducts->count()) }}
                </span>
            </div>

            @if($catalogProducts->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    @foreach($catalogProducts as $product)
                        @include('customer.servers.partials.product-card', compact('product', 'resellerListings', 'currencySymbol', 'linuxDistros', 'maxIpCount'))
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 px-6 py-16 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No {{ strtolower($typeLabel) }} plans are available right now. Please check back later or contact support.</p>
                </div>
            @endif
        </section>
    @else
        {{-- Browse categories (no modal) --}}
        <section>
            <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Browse server plans</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Choose a category to view pricing and place an order.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <a href="{{ route('customer.servers.index', ['type' => 'vps']) }}" class="group relative overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-8 hover:border-blue-400 dark:hover:border-blue-600 hover:shadow-lg transition-all">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950/50 flex items-center justify-center mb-5">
                                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">VPS servers</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">Isolated compute with dedicated resources. Ideal for apps, APIs, and development environments.</p>
                            <p class="inline-flex items-center gap-1.5 mt-5 text-sm font-semibold text-blue-600 dark:text-blue-400 group-hover:gap-2.5 transition-all">
                                View {{ $vpsProducts->count() }} plans
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </p>
                        </div>
                    </div>
                </a>
                <a href="{{ route('customer.servers.index', ['type' => 'dedicated_server']) }}" class="group relative overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-8 hover:border-indigo-400 dark:hover:border-indigo-600 hover:shadow-lg transition-all">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="w-12 h-12 rounded-lg bg-indigo-100 dark:bg-indigo-950/50 flex items-center justify-center mb-5">
                                <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Dedicated servers</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">Bare-metal performance for high-traffic workloads, databases, and mission-critical systems.</p>
                            <p class="inline-flex items-center gap-1.5 mt-5 text-sm font-semibold text-indigo-600 dark:text-indigo-400 group-hover:gap-2.5 transition-all">
                                View {{ $dedicatedProducts->count() }} plans
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </p>
                        </div>
                    </div>
                </a>
            </div>
        </section>
    @endif

    {{-- Owned servers --}}
    @if($services->count() > 0)
        <section class="@if($selectedType) pt-4 border-t border-slate-200 dark:border-slate-800 @endif">
            <div class="mb-6">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">
                    @if($selectedType)
                        Your {{ $typeLabel }} servers
                    @else
                        Your servers
                    @endif
                </h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Monitor status, billing, and access credentials.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                @foreach($services as $service)
                    @include('customer.servers.partials.service-card', compact('service'))
                @endforeach
            </div>
        </section>
    @elseif(! $selectedType)
        <section class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-10 text-center">
            <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
            </div>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">No servers deployed yet</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">Pick a VPS or dedicated plan above to get started. Your active servers will appear here once provisioned.</p>
        </section>
    @endif
</div>
@endsection
