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
        @if ($isCustomerCheckout ?? false)
            <p class="text-slate-600 dark:text-slate-400 mt-1">Creates a customer invoice for <strong>{{ $checkoutCustomer->name }}</strong> at your retail prices.</p>
        @else
            <p class="text-slate-600 dark:text-slate-400 mt-1">Review your wholesale order before placing it.</p>
        @endif
    </div>

    <div class="grid lg:grid-cols-3 gap-6" x-data="{
        applyWallet: {{ old('apply_wallet') ? 'true' : 'false' }},
        walletBalance: {{ (float) $wallet->balance }},
        total: {{ (float) $total }},
        get walletApplied() {
            return this.applyWallet ? Math.min(this.walletBalance, this.total) : 0;
        },
        get amountDue() {
            return Math.max(0, this.total - this.walletApplied);
        }
    }">
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
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Domain Orders</h2>
                <div class="space-y-4">
                    @foreach($items as $item)
                        <div class="flex justify-between items-start p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $item['domain'] }}{{ $item['extension'] }}</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                    @if(($item['type'] ?? 'domain') === 'domain_renewal')
                                        <span class="text-amber-700 dark:text-amber-300 font-medium">Renewal</span> ·
                                    @else
                                        <span class="text-emerald-700 dark:text-emerald-300 font-medium">Registration</span> ·
                                    @endif
                                    {{ $item['years'] }} Year{{ $item['years'] > 1 ? 's' : '' }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($item['total'], 2) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($isCustomerCheckout ?? false)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Bill to customer</h2>
                    <p class="font-semibold">{{ $checkoutCustomer->name }}</p>
                    <p class="text-sm text-slate-500">{{ $checkoutCustomer->email }}</p>
                </div>
                <div class="bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                    <p class="text-sm text-amber-900 dark:text-amber-200">No wallet charge at checkout. Record payment on the customer invoice after they pay you (M-Pesa, cash, etc.). Domains push when the invoice is fully paid.</p>
                </div>
            @else
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
                <div class="bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-purple-900 dark:text-purple-200 mb-2">Payment</h3>
                    <p class="text-sm text-purple-800 dark:text-purple-300">You can apply your wallet balance at checkout. Any remaining amount can be paid via M-Pesa, card, PayPal, or manual proof.</p>
                </div>
            @endif

            <!-- Place Order Form -->
            <form method="POST" action="{{ route('reseller.checkout.process') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                @csrf

                @if(!($isCustomerCheckout ?? false) && $wallet->balance > 0)
                <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm font-medium text-emerald-900 dark:text-emerald-200">Wallet Balance</p>
                        <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300">KSH {{ number_format($wallet->balance, 2) }}</p>
                    </div>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="apply_wallet" value="1" x-model="applyWallet" class="mt-1 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-slate-700 dark:text-slate-300">
                            Apply wallet balance to this order
                            <span class="block text-xs text-slate-500 dark:text-slate-400 mt-1" x-show="applyWallet">
                                Up to KSH {{ number_format($walletApplicable, 2) }} will be used from your wallet.
                            </span>
                        </span>
                    </label>
                </div>
                @elseif(!($isCustomerCheckout ?? false))
                <div class="mb-6 p-4 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Wallet balance: <strong class="text-slate-900 dark:text-white">KSH 0.00</strong>. <a href="{{ route('reseller.wallet.index') }}" class="text-purple-600 dark:text-purple-400 hover:underline">Top up your wallet</a> to pay from balance.</p>
                </div>
                @endif

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
                        {{ ($isCustomerCheckout ?? false) ? 'Create customer invoice' : 'Place Order' }}
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
                        <span class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($subtotal, 2) }}</span>
                    </div>

                    @if($taxEnabled)
                        <div class="flex justify-between items-center">
                            <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                            <span class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($tax, 2) }}</span>
                        </div>
                    @endif

                    <div class="flex justify-between items-center">
                        <span class="text-slate-600 dark:text-slate-400">Order Total</span>
                        <span class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($total, 2) }}</span>
                    </div>

                    <div class="flex justify-between items-center text-emerald-700 dark:text-emerald-300" x-show="walletApplied > 0" x-cloak>
                        <span>Wallet Applied</span>
                        <span class="font-semibold">- KSH <span x-text="walletApplied.toFixed(2)"></span></span>
                    </div>

                    <div class="flex justify-between items-center pt-3 border-t border-slate-200 dark:border-slate-700">
                        <span class="text-lg font-semibold text-slate-900 dark:text-white">Amount Due</span>
                        <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">KSH <span x-text="amountDue.toFixed(2)"></span></span>
                    </div>
                </div>

                <p class="text-xs text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-800 p-3 rounded">
                    <span x-show="amountDue <= 0">Your wallet will cover this order in full when you place it.</span>
                    <span x-show="amountDue > 0">After placing the order, pay any remaining balance to register your domains.</span>
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
