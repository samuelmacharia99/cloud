@extends('layouts.reseller')

@section('title', 'Shopping Cart')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Shopping Cart</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Your Cart</h1>
        @if (($cartContext['mode'] ?? 'self') === 'customer' && $checkoutCustomer)
            <p class="text-slate-600 dark:text-slate-400 mt-1">Whitelabel checkout for <strong>{{ $checkoutCustomer->name }}</strong> at your retail prices.</p>
        @else
            <p class="text-slate-600 dark:text-slate-400 mt-1">Review domain orders before checkout (wholesale to your account).</p>
        @endif
    </div>

    @if(count($items) > 0)
        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Cart Items -->
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <!-- Header -->
                    <div class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Domain Orders ({{ count($items) }})</h2>
                    </div>

                    <!-- Items -->
                    <div class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach($items as $key => $item)
                            <div class="px-6 py-4 flex justify-between items-center hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                <div>
                                    <p class="font-semibold text-slate-900 dark:text-white">{{ $item['domain'] }}{{ $item['extension'] }}</p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1 flex flex-wrap gap-2">
                                        @if(($item['type'] ?? 'domain') === 'domain_renewal')
                                            <span class="px-2 py-1 bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 rounded text-xs font-medium">Renewal</span>
                                        @else
                                            <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300 rounded text-xs font-medium">Registration</span>
                                        @endif
                                        <span class="px-2 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded text-xs font-medium">
                                            {{ $item['years'] }} Year{{ $item['years'] > 1 ? 's' : '' }}
                                        </span>
                                    </p>
                                </div>

                                <div class="flex items-center gap-6">
                                    <div class="text-right">
                                        <p class="text-sm text-slate-600 dark:text-slate-400">Unit Price</p>
                                        <p class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($item['price'], 2) }}</p>
                                    </div>

                                    <div class="text-right">
                                        <p class="text-sm text-slate-600 dark:text-slate-400">Total</p>
                                        <p class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($item['total'], 2) }}</p>
                                    </div>

                                    <form method="POST" action="{{ route('reseller.cart.remove', $key) }}" data-confirm='Remove this item?'>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Order Summary Sidebar -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-24">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Summary</h3>

                    <div class="space-y-3 mb-6 border-b border-slate-200 dark:border-slate-700 pb-6">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                            <span class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($subtotal, 2) }}</span>
                        </div>

                        @if($taxEnabled)
                            <div class="flex justify-between items-center">
                                <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                                <span class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($tax, 2) }}</span>
                            </div>
                        @endif

                        <div class="flex justify-between items-center pt-3">
                            <span class="text-lg font-semibold text-slate-900 dark:text-white">Total</span>
                            <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">KES {{ number_format($total, 2) }}</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a href="{{ route('reseller.checkout.show') }}" class="block w-full px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-center">
                            Proceed to Checkout
                        </a>

                        <form method="POST" action="{{ route('reseller.cart.clear') }}" data-confirm='Clear entire cart?'>
                            @csrf
                            <button type="submit" class="w-full px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                                Clear Cart
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
            </svg>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Your cart is empty</h3>
            <p class="text-slate-600 dark:text-slate-400 mb-6">Add some domains to get started</p>
            <a href="{{ route('reseller.domains.index') }}" class="inline-flex items-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                Browse Domains
            </a>
        </div>
    @endif
</div>
@endsection
