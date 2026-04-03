@extends('layouts.customer')

@section('title', 'Payment Receipt')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Payment Receipt</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Payment details and status</p>
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column (Main Content) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Hero Section: Amount & Status -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 dark:from-blue-900 dark:to-blue-950 rounded-xl p-8 text-white">
                <p class="text-sm font-medium text-blue-100 uppercase tracking-wide">Payment Amount</p>
                <div class="mt-3">
                    <span class="text-4xl font-bold">
                        @if ($payment->currency === 'KES')
                            Ksh
                        @elseif ($payment->currency === 'USD')
                            $
                        @elseif ($payment->currency === 'GBP')
                            £
                        @else
                            {{ $payment->currency }}
                        @endif
                        {{ number_format($payment->amount, 2) }}
                    </span>
                </div>
                <div class="mt-6 pt-6 border-t border-blue-400/30">
                    <x-status-badge :status="$payment->status" type="payment" />
                </div>
            </div>

            <!-- Payment Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Payment Details</h2>
                <div class="space-y-4">
                    <!-- Method -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Payment Method</span>
                        <div>
                            <x-payment-badge :method="$payment->payment_method" />
                        </div>
                    </div>

                    <!-- Transaction Reference -->
                    @if ($payment->transaction_reference)
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Transaction ID</span>
                            <code class="text-sm text-slate-900 dark:text-white font-mono bg-slate-50 dark:bg-slate-800 px-3 py-1 rounded">
                                {{ $payment->transaction_reference }}
                            </code>
                        </div>
                    @endif

                    <!-- Currency -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Currency</span>
                        <span class="text-slate-900 dark:text-white font-medium">{{ $payment->currency }}</span>
                    </div>

                    <!-- Paid Date -->
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Date Processed</span>
                        <span class="text-slate-900 dark:text-white">
                            @if ($payment->paid_at)
                                {{ $payment->paid_at->format('M d, Y \a\t h:i A') }}
                            @else
                                <span class="text-slate-400">Not yet paid</span>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            <!-- Related Invoice (if exists) -->
            @if ($payment->invoice)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="flex items-center justify-between p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $payment->invoice->invoice_number }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <x-currency-formatter :amount="$payment->invoice->total" :currency="$payment->invoice->currency ?? 'KES'" :showSymbol="true" />
                                <span class="text-slate-400">•</span>
                                <x-status-badge :status="$payment->invoice->status" type="invoice" />
                            </div>
                        </div>
                        <a href="{{ route('customer.invoices.show', $payment->invoice) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                            View Invoice →
                        </a>
                    </div>
                </div>
            @endif

            <!-- Notes (if exists) -->
            @if ($payment->notes)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 whitespace-pre-wrap">{{ $payment->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Right Sidebar -->
        <div class="space-y-6">
            <!-- Quick Info Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase text-xs tracking-wide mb-4">Payment Summary</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <div class="mt-2">
                            <x-status-badge :status="$payment->status" type="payment" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <p class="text-lg font-bold text-slate-900 dark:text-white mt-1">
                            <x-currency-formatter :amount="$payment->amount" :currency="$payment->currency" />
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Method</p>
                        <div class="mt-2 flex items-center gap-2">
                            <x-payment-method-icon :method="$payment->payment_method" class="w-5 h-5" />
                            <span class="text-sm text-slate-900 dark:text-white">{{ $payment->payment_method->label() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase text-xs tracking-wide mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white mt-1">{{ $payment->created_at->format('M d, Y') }}</p>
                    </div>
                    @if ($payment->paid_at)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Paid At</p>
                            <p class="text-slate-900 dark:text-white mt-1">{{ $payment->paid_at->format('M d, Y') }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            <div class="space-y-2">
                <button onclick="window.print()" class="w-full px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition-colors text-sm">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print Receipt
                </button>
                <a href="{{ route('customer.payments.index') }}" class="block w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors text-sm text-center">
                    Back to Payments
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
