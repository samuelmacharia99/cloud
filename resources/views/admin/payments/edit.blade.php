@extends('layouts.admin')

@section('title', 'Edit Payment #' . str_pad($payment->id, 5, '0', STR_PAD_LEFT))

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.payments.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Payments</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.payments.show', $payment) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">#{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Update Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Update payment status and notes. Amount and method cannot be changed.</p>
    </div>

    <!-- Form Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <form action="{{ route('admin.payments.update', $payment) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Read-Only Details -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Details (Read-Only)</h2>
                <div class="grid grid-cols-2 gap-6 bg-slate-50 dark:bg-slate-800/30 p-4 rounded-lg">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <div class="mt-2">
                            <x-currency-formatter :amount="$payment->amount" :currency="$payment->currency" class="text-lg font-semibold" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Method</p>
                        <div class="mt-2">
                            <x-payment-badge :method="$payment->payment_method" />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Transaction Reference</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-2 font-mono">{{ $payment->transaction_reference ?? 'None' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Customer</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-2">{{ $payment->user->name }}</p>
                    </div>
                </div>
            </div>

            <!-- Status Update Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Update Status</h2>
                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current Status</p>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg">
                            <x-status-badge :status="$payment->status" type="payment" />
                        </div>
                    </div>

                    <x-form-select
                        label="New Status"
                        name="status"
                        :options="$statuses"
                        :value="$payment->status->value"
                    />

                    <div class="p-3 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 rounded-lg text-sm text-blue-900 dark:text-blue-200">
                        <p><strong>Allowed transitions:</strong></p>
                        <ul class="mt-2 ml-4 space-y-1 text-xs list-disc">
                            <li>Pending → Completed or Failed</li>
                            <li>Completed → Reversed</li>
                            <li>Failed and Reversed are final states</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Notes Section -->
            <div class="pb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                    Notes <span class="text-slate-400 text-xs">(optional)</span>
                </label>
                <textarea
                    name="notes"
                    rows="4"
                    placeholder="Add or update notes about this payment..."
                    class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 dark:placeholder-slate-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                >{{ old('notes', $payment->notes) }}</textarea>
            </div>

            <!-- Related Invoice (if exists) -->
            @if ($payment->invoice)
                <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $payment->invoice->invoice_number }}</p>
                                <div class="flex items-center gap-2 mt-1 text-sm">
                                    <x-currency-formatter :amount="$payment->invoice->total" currency="KES" />
                                    <span class="text-slate-400">•</span>
                                    <x-status-badge :status="$payment->invoice->status" type="invoice" />
                                </div>
                            </div>
                            <a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                                View Invoice →
                            </a>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Action Buttons -->
            <div class="flex items-center gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Update Payment
                </button>
                <a href="{{ route('admin.payments.show', $payment) }}" class="px-6 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium rounded-lg transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
