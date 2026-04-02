@extends('layouts.admin')

@section('title', 'Edit Payment')

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
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update payment details and status.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.payments.update', $payment) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Customer -->
                    <div>
                        <label for="user_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Customer</label>
                        <select id="user_id" name="user_id" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('user_id') border-red-500 @enderror" required>
                            <option value="">Select a customer...</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('user_id', $payment->user_id) == $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                            @endforeach
                        </select>
                        @error('user_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Amount -->
                    <div>
                        <label for="amount" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Payment Amount</label>
                        <div class="relative">
                            <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                            <input type="number" id="amount" name="amount" value="{{ old('amount', $payment->amount) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('amount') border-red-500 @enderror" required>
                        </div>
                        @error('amount')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Gateway -->
                    <div>
                        <label for="gateway" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Payment Gateway</label>
                        <select id="gateway" name="gateway" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('gateway') border-red-500 @enderror" required>
                            <option value="">Select a gateway...</option>
                            <option value="stripe" @selected(old('gateway', $payment->gateway) === 'stripe')>Stripe</option>
                            <option value="paypal" @selected(old('gateway', $payment->gateway) === 'paypal')>PayPal</option>
                            <option value="bank_transfer" @selected(old('gateway', $payment->gateway) === 'bank_transfer')>Bank Transfer</option>
                            <option value="manual" @selected(old('gateway', $payment->gateway) === 'manual')>Manual</option>
                        </select>
                        @error('gateway')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status</label>
                        <select id="status" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('status') border-red-500 @enderror" required>
                            <option value="pending" @selected(old('status', $payment->status) === 'pending')>Pending</option>
                            <option value="completed" @selected(old('status', $payment->status) === 'completed')>Completed</option>
                            <option value="failed" @selected(old('status', $payment->status) === 'failed')>Failed</option>
                            <option value="refunded" @selected(old('status', $payment->status) === 'refunded')>Refunded</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Invoice (Optional) -->
                    <div>
                        <label for="invoice_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Related Invoice <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="invoice_id" name="invoice_id" value="{{ old('invoice_id', $payment->invoice_id) }}" placeholder="Enter invoice ID (optional)" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('invoice_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Transaction ID -->
                    <div>
                        <label for="transaction_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Transaction ID <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="transaction_id" name="transaction_id" value="{{ old('transaction_id', $payment->transaction_id) }}" placeholder="External transaction ID..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('transaction_id') border-red-500 @enderror">
                        @error('transaction_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Add any notes about this payment..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('notes', $payment->notes) }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.payments.show', $payment) }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
