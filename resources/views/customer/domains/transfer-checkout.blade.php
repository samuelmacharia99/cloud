@extends('layouts.customer')

@section('title', 'Domain Transfer Checkout')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Domains
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <a href="{{ route('customer.domains.transfer-form') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Transfer Domain
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Checkout</p>
</div>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domain Transfer Checkout</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Review and confirm your domain transfer order</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Transfer Details</h2>

                <div class="space-y-4">
                    <!-- Domain -->
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Domain</p>
                        <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ $domain->name }}{{ $domain->extension }}</p>
                    </div>

                    <!-- Transfer Type -->
                    <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Service Type</p>
                        <p class="font-semibold text-slate-900 dark:text-white">Domain Transfer</p>
                    </div>

                    <!-- Current Registrar -->
                    @if($domain->old_registrar)
                        <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Current Registrar</p>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $domain->old_registrar }}</p>
                        </div>
                    @endif

                    <!-- Status -->
                    <div class="p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-800">
                        <p class="text-sm text-blue-900 dark:text-blue-300">
                            <strong>Status:</strong> Your transfer request has been created and is pending confirmation. After payment, we'll complete the transfer process.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Terms -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Confirmation</h2>

                <form action="{{ route('customer.domains.transfer-checkout-confirm') }}" method="POST" class="space-y-6">
                    @csrf

                    <div class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-800">
                        <input
                            type="checkbox"
                            id="agree_terms"
                            name="agree_terms"
                            class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 mt-1"
                            required
                        >
                        <label for="agree_terms" class="text-sm text-slate-700 dark:text-slate-300">
                            I agree to the terms and conditions. I understand that the domain transfer will be initiated after payment is confirmed and may take 3-5 business days to complete.
                        </label>
                    </div>

                    @error('agree_terms')
                        <p class="text-red-600 dark:text-red-400 text-sm">{{ $message }}</p>
                    @enderror

                    <!-- Action Buttons -->
                    <div class="flex gap-3">
                        <a href="{{ route('customer.domains.transfer-form') }}" class="flex-1 px-6 py-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition text-center">
                            Back
                        </a>
                        <button
                            type="submit"
                            class="flex-1 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold transition"
                        >
                            Proceed to Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Order Summary Sidebar -->
        <div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sticky top-6 space-y-4">
                <h3 class="font-bold text-slate-900 dark:text-white">Order Summary</h3>

                <div class="space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Transfer Fee</span>
                        <span class="text-slate-900 dark:text-white font-medium">{{ $currencyCode }} {{ number_format($subtotal, 2) }}</span>
                    </div>

                    @if($taxEnabled && $tax > 0)
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                            <span class="text-slate-900 dark:text-white font-medium">{{ $currencyCode }} {{ number_format($tax, 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
                    <div class="flex justify-between">
                        <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                        <span class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ $currencyCode }} {{ number_format($total, 2) }}</span>
                    </div>
                </div>

                <div class="bg-slate-50 dark:bg-slate-800 p-4 rounded-lg space-y-2 text-sm text-slate-600 dark:text-slate-400">
                    <p>✓ Secure payment processing</p>
                    <p>✓ Transfer initiated after payment</p>
                    <p>✓ 3-5 business day completion</p>
                </div>

                <a href="{{ route('customer.domains.index') }}" class="block w-full px-6 py-2 text-center bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
