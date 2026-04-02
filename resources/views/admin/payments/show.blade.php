@extends('layouts.admin')

@section('title', 'Payment #' . str_pad($payment->id, 5, '0', STR_PAD_LEFT))

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.payments.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Payments</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Payment #{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $payment->user->name }} • {{ $payment->user->email }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($payment->status === 'completed')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($payment->status === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif($payment->status === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif($payment->status === 'refunded')
                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                        @endif
                    ">
                        {{ ucfirst($payment->status) }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.payments.edit', $payment) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Payment
                </a>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Payment Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Payment Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">${{ number_format($payment->amount, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Gateway</p>
                        <p class="text-lg font-semibold text-slate-900 dark:text-white mt-1">{{ ucfirst(str_replace('_', ' ', $payment->gateway)) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst($payment->status) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $payment->created_at->format('M d, Y') }}</p>
                    </div>
                    @if ($payment->transaction_id)
                        <div class="col-span-2">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Transaction ID</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1 font-mono">{{ $payment->transaction_id }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Related Invoice -->
            @if ($payment->invoice)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="flex items-center justify-between p-4 border border-slate-200 dark:border-slate-800 rounded-lg">
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">Invoice #{{ str_pad($payment->invoice->id, 5, '0', STR_PAD_LEFT) }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">${{ number_format($payment->invoice->total, 2) }} • {{ ucfirst($payment->invoice->status) }}</p>
                        </div>
                        <a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                            View Invoice
                        </a>
                    </div>
                </div>
            @endif

            <!-- Notes -->
            @if ($payment->notes)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ $payment->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($payment->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $payment->user->name }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $payment->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $payment->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Method</h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        @if ($payment->gateway === 'stripe')
                            <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M13.976 9.15c-2.172-.806-3.356-1.629-3.356-2.76 0-.968.756-1.592 1.962-1.592.891 0 1.666.507 2.91 1.593l1.857-1.448C13.748 3.575 12.662 2.87 11.58 2.87c-2.688 0-4.572 1.847-4.572 4.659 0 2.422 1.256 4.102 3.803 5.290 2.05.987 3.025 1.629 3.025 2.675 0 1.085-.703 1.628-1.897 1.628-1.252 0-2.236-.75-2.236-1.91 0-.484.211-1.093.548-1.676l-2.25 1.324c-.267.526-.544 1.251-.544 1.852 0 2.48 1.949 4.175 4.528 4.175 2.81 0 4.765-1.848 4.765-4.659 0-2.392-1.267-4.107-3.962-5.278z"/>
                            </svg>
                        @elseif ($payment->gateway === 'paypal')
                            <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.067 8.478c.492.88.556 2.014.3 3.327-.74 3.806-3.276 5.12-6.514 5.122h-.5a.805.805 0 00-.794.71l-.04.22-.63 3.993-.028.15a.806.806 0 01-.796.71h-2.39a.527.527 0 01-.519-.629l.38-2.41.6-3.8a.806.806 0 01.796-.71h.5c2.75-.002 4.905-.986 5.52-3.85.234-1.2.12-2.197-.365-2.93"/>
                            </svg>
                        @else
                            <svg class="w-5 h-5 text-slate-600" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M3 6h18v2H3V6z"/>
                            </svg>
                        @endif
                        <span class="text-sm text-slate-900 dark:text-white font-medium">{{ ucfirst(str_replace('_', ' ', $payment->gateway)) }}</span>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $payment->created_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white">{{ $payment->updated_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
