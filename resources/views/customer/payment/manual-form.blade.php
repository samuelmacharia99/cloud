@extends('layouts.customer')

@section('title', 'Manual Payment — Invoice ' . $invoice->invoice_number)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Manual Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Submit your payment details below for admin approval.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
                <form method="POST" action="{{ route('customer.payment.manual-submit', $invoice) }}" class="space-y-6">
                    @csrf

                    <!-- Info Box -->
                    <div class="p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <p class="text-sm text-blue-900 dark:text-blue-300">
                            <strong>How it works:</strong> Submit your payment details below. An admin will verify your payment and approve it, after which your services will be activated.
                        </p>
                    </div>

                    <!-- Payment Reference / Transaction ID -->
                    <div>
                        <label for="payment_reference" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                            Payment Reference / Transaction ID
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
                        </label>
                        <input type="text"
                               id="payment_reference"
                               name="payment_reference"
                               value="{{ old('payment_reference') }}"
                               placeholder="e.g., Bank slip number, mobile money reference"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('payment_reference') border-red-500 @enderror">
                        @error('payment_reference')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Bank Name -->
                    <div>
                        <label for="bank_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                            Bank / Payment Method Name
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
                        </label>
                        <input type="text"
                               id="bank_name"
                               name="bank_name"
                               value="{{ old('bank_name') }}"
                               placeholder="e.g., KCB, M-Pesa, PayPal"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('bank_name') border-red-500 @enderror">
                        @error('bank_name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Account Name -->
                    <div>
                        <label for="account_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                            Account Name (Your Name on Bank Account)
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
                        </label>
                        <input type="text"
                               id="account_name"
                               name="account_name"
                               value="{{ old('account_name', auth()->user()->name) }}"
                               placeholder="Your name as it appears on the bank account"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('account_name') border-red-500 @enderror">
                        @error('account_name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                            Additional Notes
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
                        </label>
                        <textarea id="notes"
                                  name="notes"
                                  rows="4"
                                  placeholder="Any additional information to help us verify your payment (e.g., date and time of transfer, screenshots, etc.)"
                                  class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none @error('notes') border-red-500 @enderror">{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                        <a href="{{ route('customer.invoices.show', $invoice) }}"
                           class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                            Cancel
                        </a>
                        <button type="submit" class="flex-1 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Submit Payment Details
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Invoice Summary -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sticky top-6">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Invoice Summary</h3>

                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Invoice No.</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Amount</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->total > 0 ? 'Ksh ' : '' }}{{ number_format($invoice->total, 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Due Date</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->due_date->format('M d, Y') }}</span>
                    </div>
                </div>

                <div class="my-4 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        Ksh {{ number_format($invoice->total, 0) }}
                    </p>
                </div>

                <!-- Info -->
                <div class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <p class="text-xs text-amber-900 dark:text-amber-300">
                        <strong>Status:</strong> Your submission will be reviewed by our admin team. You'll receive a notification once approved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
