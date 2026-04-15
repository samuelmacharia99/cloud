@extends('layouts.reseller')

@section('title', $catalogItem->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('reseller.catalog.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">My Catalog</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $catalogItem->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header with Actions -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $catalogItem->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                @if($catalogItem->isCustom())
                    Custom Product
                @else
                    Based on: {{ $catalogItem->adminProduct?->name }}
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('reseller.catalog.edit', $catalogItem) }}" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                Edit
            </a>
            <form method="POST" action="{{ route('reseller.catalog.destroy', $catalogItem) }}" class="inline" onsubmit="return confirm('Are you sure?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Product Information</h2>

                <div class="space-y-6">
                    <!-- Type -->
                    <div>
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Type</p>
                        <p class="text-slate-900 dark:text-white">{{ Product::typeLabel($catalogItem->type) }}</p>
                    </div>

                    <!-- Status -->
                    <div>
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1">Status</p>
                        <div>
                            @if($catalogItem->is_active)
                                <span class="px-3 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded text-sm font-medium">Active</span>
                            @else
                                <span class="px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded text-sm font-medium">Inactive</span>
                            @endif
                        </div>
                    </div>

                    <!-- Description -->
                    @if($catalogItem->description)
                        <div>
                            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-2">Description</p>
                            <p class="text-slate-700 dark:text-slate-300 leading-relaxed">{{ $catalogItem->description }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Pricing Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Pricing</h2>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <!-- Monthly -->
                        <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-2">Monthly</p>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">
                                @if($catalogItem->monthly_price)
                                    ${{ number_format($catalogItem->monthly_price, 2) }}
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </p>
                        </div>

                        <!-- Yearly -->
                        <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-2">Yearly</p>
                            <p class="text-2xl font-bold text-slate-900 dark:text-white">
                                @if($catalogItem->yearly_price)
                                    ${{ number_format($catalogItem->yearly_price, 2) }}
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <!-- Setup Fee -->
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-2">Setup Fee</p>
                        <p class="text-xl font-bold text-slate-900 dark:text-white">
                            @if($catalogItem->setup_fee)
                                ${{ number_format($catalogItem->setup_fee, 2) }}
                            @else
                                $0.00
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            @if(!$catalogItem->isCustom())
                <!-- Base Product Info -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Admin Product</h3>

                    <div class="space-y-4 text-sm">
                        <div>
                            <p class="text-slate-600 dark:text-slate-400 mb-1">Product</p>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $catalogItem->adminProduct?->name }}</p>
                        </div>

                        <div>
                            <p class="text-slate-600 dark:text-slate-400 mb-1">Wholesale Monthly</p>
                            <p class="font-medium text-slate-900 dark:text-white">
                                @if($catalogItem->adminProduct?->wholesale_monthly_price)
                                    ${{ number_format($catalogItem->adminProduct->wholesale_monthly_price, 2) }}
                                @else
                                    —
                                @endif
                            </p>
                        </div>

                        <div>
                            <p class="text-slate-600 dark:text-slate-400 mb-1">Wholesale Yearly</p>
                            <p class="font-medium text-slate-900 dark:text-white">
                                @if($catalogItem->adminProduct?->wholesale_yearly_price)
                                    ${{ number_format($catalogItem->adminProduct->wholesale_yearly_price, 2) }}
                                @else
                                    —
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Margin Analysis -->
                @if($catalogItem->getMonthlyMargin() !== null || $catalogItem->getYearlyMargin() !== null)
                    <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl border border-emerald-200 dark:border-emerald-800 p-6">
                        <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-300 mb-4">Margin Analysis</h3>

                        <div class="space-y-4 text-sm">
                            @if($catalogItem->getMonthlyMargin() !== null)
                                <div>
                                    <p class="text-emerald-700 dark:text-emerald-400 mb-1">Monthly Margin</p>
                                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                                        ${{ number_format($catalogItem->getMonthlyMargin(), 2) }}
                                    </p>
                                    <p class="text-xs text-emerald-700 dark:text-emerald-400">
                                        {{ number_format($catalogItem->getMonthlyMarginPercent(), 1) }}% markup
                                    </p>
                                </div>
                            @endif

                            @if($catalogItem->getYearlyMargin() !== null)
                                <div>
                                    <p class="text-emerald-700 dark:text-emerald-400 mb-1">Yearly Margin</p>
                                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                                        ${{ number_format($catalogItem->getYearlyMargin(), 2) }}
                                    </p>
                                    <p class="text-xs text-emerald-700 dark:text-emerald-400">
                                        {{ number_format($catalogItem->getYearlyMarginPercent(), 1) }}% markup
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @else
                <!-- Custom Product Note -->
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-200 dark:border-blue-800 p-6">
                    <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-2">Custom Product</h3>
                    <p class="text-sm text-blue-800 dark:text-blue-400">This is a custom product with pricing set by you.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
