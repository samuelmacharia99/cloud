@extends('layouts.customer')

@section('title', 'Shopping Cart')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Shopping Cart</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and manage your selected services</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Items -->
        <div class="lg:col-span-2">
            @if($itemCount > 0)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Billing</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Price</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach($cartItems as $item)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                        <td class="px-6 py-4">
                                            <div>
                                                <p class="font-medium text-slate-900 dark:text-white">{{ $item['name'] }}</p>
                                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $item['description'] }}</p>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                            @if(isset($item['billing_cycle']))
                                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                    {{ ucfirst($item['billing_cycle']) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                    {{ $item['years'] ?? 1 }} Year(s)
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <p class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($item['amount'], 0) }}</p>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <form action="{{ route('customer.cart.remove', $item['key']) }}" method="POST" style="display: inline;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 text-sm font-medium">
                                                    Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <p class="text-slate-500 dark:text-slate-400 text-lg mb-4">Your cart is empty</p>
                    <a href="{{ route('customer.deploy-service') }}" class="inline-flex items-center px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Continue Shopping
                    </a>
                </div>
            @endif
        </div>

        <!-- Summary -->
        @if($itemCount > 0)
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Summary</h3>

                    <div class="space-y-3 mb-4 pb-4 border-b border-slate-200 dark:border-slate-700">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                            <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($subtotal, 0) }}</span>
                        </div>

                        @if($taxEnabled)
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                                <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($tax, 0) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-between mb-6">
                        <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">Ksh {{ number_format($total, 0) }}</span>
                    </div>

                    <a href="{{ route('customer.checkout.show') }}" class="block w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-center transition mb-3">
                        Proceed to Checkout
                    </a>

                    <form action="{{ route('customer.cart.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                            Clear Cart
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
