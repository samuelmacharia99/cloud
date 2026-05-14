@extends('layouts.reseller')

@section('title', 'Checkout')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Checkout</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Order Review</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review your order details before placing it.</p>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Order Details -->
        <div class="lg:col-span-2 space-y-6">
            @if ($errors->any())
                <div class="bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-red-900 dark:text-red-200 mb-2">Errors:</h3>
                    <ul class="text-sm text-red-700 dark:text-red-300 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>• {{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Order Items -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Domains to Register</h2>
                <div class="space-y-4">
                    @foreach($items as $item)
                        <div class="flex justify-between items-start p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $item['domain'] }}{{ $item['extension'] }}</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $item['years'] }} Year{{ $item['years'] > 1 ? 's' : '' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($item['total'], 2) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Your Information -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Your Information</h2>
                <div class="space-y-4">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Full Name</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $user->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Email</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $user->email }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Phone</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $user->phone ?? 'N/A' }}</p>
                    </div>
                </div>
            </div>

            <!-- Payment Info -->
            <div class="bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg p-4">
                <h3 class="text-sm font-semibold text-purple-900 dark:text-purple-200 mb-2">Payment</h3>
                <p class="text-sm text-purple-800 dark:text-purple-300">An invoice will be generated. You can pay via M-Pesa, Stripe, PayPal, or submit manual payment proof.</p>
            </div>

            <!-- Place Order Form -->
            <form method="POST" action="{{ route('reseller.checkout.process') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                @csrf

                <div class="mb-6">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="agree" required class="w-4 h-4 mt-1 rounded border-slate-300 dark:border-slate-600 text-purple-600 dark:text-purple-500 focus:ring-0 focus:border-purple-500 transition">
                        <span class="text-sm text-slate-700 dark:text-slate-300">
                            I agree to the <a href="{{ route('terms') }}" target="_blank" class="text-purple-600 dark:text-purple-400 hover:underline font-semibold">Terms of Service</a> and understand that these are domain registration orders.
                        </span>
                    </label>
                    @error('agree')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-3">
                    <a href="{{ route('reseller.cart.index') }}" class="flex-1 px-4 py-3 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                        Back to Cart
                    </a>
                    <button type="submit" class="flex-1 px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                        Place Order
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-24">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Summary</h3>

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
                        <span class="text-lg font-semibold text-slate-900 dark:text-white">Total Due</span>
                        <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">KES {{ number_format($total, 2) }}</span>
                    </div>
                </div>

                <p class="text-xs text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-800 p-3 rounded">
                    Your domains will be registered once payment is confirmed. You'll receive a confirmation email with all details.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
