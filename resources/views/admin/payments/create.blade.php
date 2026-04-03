@extends('layouts.admin')

@section('title', 'Record Payment')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.payments.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Payments</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">New Payment</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Record Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Manually log a new payment transaction.</p>
    </div>

    <!-- Form Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <form action="{{ route('admin.payments.store') }}" method="POST" class="space-y-6">
            @csrf

            <!-- User Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h2>
                <x-form-select
                    label="Select Customer"
                    name="user_id"
                    :options="$users->pluck('name', 'id')->toArray()"
                    required
                    placeholder="Choose a customer..."
                />
            </div>

            <!-- Payment Details Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Details</h2>
                <div class="space-y-4">
                    <x-form-input
                        label="Amount"
                        name="amount"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        required
                    />

                    <x-form-select
                        label="Currency"
                        name="currency"
                        :options="['KES' => 'KES', 'USD' => 'USD', 'GBP' => 'GBP']"
                        value="KES"
                    />

                    <x-form-select
                        label="Payment Method"
                        name="payment_method"
                        :options="$paymentMethods"
                        required
                        placeholder="Choose payment method..."
                    />

                    <x-form-input
                        label="Transaction Reference"
                        name="transaction_reference"
                        type="text"
                        placeholder="e.g., TXN123456789"
                    />
                </div>
            </div>

            <!-- Invoice & Status Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice & Status</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Related Invoice <span class="text-slate-400">(optional)</span>
                        </label>
                        <select name="invoice_id" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">No related invoice</option>
                            @foreach ($invoices as $invoice)
                                <option value="{{ $invoice->id }}">{{ $invoice->invoice_number }} ({{ $invoice->user->name }})</option>
                            @endforeach
                        </select>
                    </div>

                    <x-form-select
                        label="Status"
                        name="status"
                        :options="$statuses"
                        value="pending"
                    />

                    <x-form-input
                        label="Paid At"
                        name="paid_at"
                        type="datetime-local"
                    />
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
                    placeholder="Add any notes about this payment..."
                    class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 dark:placeholder-slate-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                ></textarea>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Record Payment
                </button>
                <a href="{{ route('admin.payments.index') }}" class="px-6 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium rounded-lg transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <!-- Help Text -->
    <div class="mt-8 p-4 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900/50 rounded-lg">
        <p class="text-sm text-blue-900 dark:text-blue-200">
            <strong>Tip:</strong> Select a customer and optional invoice, then enter the payment details. The system will automatically reconcile the invoice status when payment is marked as completed.
        </p>
    </div>
</div>
@endsection
