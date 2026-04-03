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
                    <x-status-badge :status="$payment->status" type="payment" />
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
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Payment Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <div class="mt-1">
                            <x-currency-formatter :amount="$payment->amount" :currency="$payment->currency" class="text-2xl font-bold" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Method</p>
                        <div class="mt-1">
                            <x-payment-badge :method="$payment->payment_method" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <div class="mt-1">
                            <x-status-badge :status="$payment->status" type="payment" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Paid At</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">
                            @if ($payment->paid_at)
                                {{ $payment->paid_at->format('M d, Y h:i A') }}
                            @else
                                <span class="text-slate-400">Not yet paid</span>
                            @endif
                        </p>
                    </div>
                    @if ($payment->transaction_reference)
                        <div class="col-span-2">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Transaction Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1 font-mono bg-slate-50 dark:bg-slate-800/50 px-3 py-2 rounded border border-slate-200 dark:border-slate-700">{{ $payment->transaction_reference }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Related Invoice -->
            @if ($payment->invoice)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="flex items-center justify-between p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $payment->invoice->invoice_number }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <x-currency-formatter :amount="$payment->invoice->total" currency="KES" :showSymbol="true" />
                                <span class="text-slate-400">•</span>
                                <x-status-badge :status="$payment->invoice->status" type="invoice" />
                            </div>
                        </div>
                        <a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                            View
                        </a>
                    </div>
                </div>
            @endif

            <!-- Notes -->
            @if ($payment->notes)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/50 px-3 py-2 rounded border border-slate-200 dark:border-slate-700">{{ $payment->notes }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                            {{ strtoupper(substr($payment->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white text-sm">{{ $payment->user->name }}</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $payment->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $payment->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Method</h3>
                <div class="flex items-center gap-3">
                    <x-payment-method-icon :method="$payment->payment_method" class="w-6 h-6" />
                    <span class="text-sm text-slate-900 dark:text-white font-medium">{{ $payment->payment_method->label() }}</span>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
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
