@extends('layouts.customer')

@section('title', 'Services & Plans')

@section('content')
@php
    $catalogService = app(\App\Services\ResellerCustomerCatalogService::class);
    $hostingProducts = $products->filter(fn ($product) => $catalogService->isHostingCatalogType($product->type));
    $otherProducts = $products->reject(fn ($product) => $catalogService->isHostingCatalogType($product->type));
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services &amp; Plans</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Browse available services and add-ons for your account.</p>
    </div>

    @if ($hostingProducts->isNotEmpty())
        <div class="bg-gradient-to-r from-blue-50 to-emerald-50 dark:from-slate-800 dark:to-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 md:p-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Deploy hosting</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1 max-w-xl">
                        Choose your language, database, and hosting package to get started.
                    </p>
                </div>
                <a href="{{ route('customer.select-techstack') }}" class="inline-flex items-center justify-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition shrink-0">
                    Choose tech stack
                </a>
            </div>
        </div>
    @endif

    @if ($otherProducts->count())
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Other services</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                @foreach ($otherProducts as $product)
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 flex flex-col">
                        <h3 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h3>
                        @if ($product->description)
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 flex-1">{{ $product->description }}</p>
                        @endif
                        <p class="mt-4 text-2xl font-bold text-blue-600">KES {{ number_format($product->monthly_price ?? 0, 2) }}<span class="text-sm font-normal text-slate-500">/mo</span></p>
                        @if ($product->isOrderable())
                            <form action="{{ route('customer.catalog.add', $product) }}" method="POST" class="mt-4 space-y-3">
                                @csrf
                                <select name="billing_cycle" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-sm">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="semi-annual">Semi-annual</option>
                                    <option value="annual">Annual</option>
                                </select>
                                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Add to Cart</button>
                            </form>
                        @else
                            <p class="mt-4 text-sm text-amber-600">Contact support to order this plan.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($hostingProducts->isEmpty())
        <div class="p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border text-slate-500">No services are available to order right now.</div>
    @endif
</div>
@endsection
