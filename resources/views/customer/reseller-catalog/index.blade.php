@extends('layouts.customer')

@section('title', 'Services & Plans')

@section('content')
@php
    use App\Models\Product;
    $catalogService = app(\App\Services\ResellerCustomerCatalogService::class);
    $orderable = $products->filter(fn ($product) => $product->isOrderable());
    $unavailable = $products->reject(fn ($product) => $product->isOrderable());
    $groups = $orderable->groupBy(fn ($product) => $product->type ?: 'other');
    $groupOrder = ['shared_hosting', 'container_hosting', 'email_hosting', 'vps', 'dedicated_server', 'ssl'];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services &amp; Plans</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Choose a package and add it to your cart — no tech stack selection required.</p>
    </div>

    @if (session('info'))
        <div class="rounded-lg border border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200 px-4 py-3 text-sm">
            {{ session('info') }}
        </div>
    @endif
    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200 px-4 py-3 text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-900/20 dark:text-rose-200 px-4 py-3 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if ($orderable->isEmpty() && $unavailable->isEmpty())
        <div class="ui-card p-12 text-center text-slate-500">
            No services are available to order right now.
        </div>
    @else
        @foreach ($groupOrder as $type)
            @continue(empty($groups[$type]) || $groups[$type]->isEmpty())
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">{{ Product::typeLabel($type) }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    @foreach ($groups[$type] as $product)
                        @include('customer.reseller-catalog.partials.product-card', ['product' => $product])
                    @endforeach
                </div>
            </div>
            @php unset($groups[$type]); @endphp
        @endforeach

        @foreach ($groups as $type => $typeProducts)
            @continue($typeProducts->isEmpty())
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">{{ Product::typeLabel($type) }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    @foreach ($typeProducts as $product)
                        @include('customer.reseller-catalog.partials.product-card', ['product' => $product])
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($unavailable->isNotEmpty())
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Unavailable</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    @foreach ($unavailable as $product)
                        <div class="ui-card p-6 opacity-70">
                            <h3 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h3>
                            <p class="mt-4 text-sm text-amber-600 dark:text-amber-400">Contact support to order this plan.</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
