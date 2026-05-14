@extends('layouts.reseller')

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
                <form method="POST" action="{{ route('reseller.payment.manual-submit', $invoice) }}" class="space-y-6">
                    @csrf

                    <!-- Info Box -->
                    <div class="p-4 bg-purple-50 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800 rounded-lg">
                        <p class="text-sm text-purple-900 dark:text-purple-300">
                            <strong>How it works:</strong> Submit your payment details below. An admin will verify your payment and approve it, after which your domain order will be activated.
                        </p>
                    </div>

                    <!-- Payment Reference / Transaction ID -->
                    <div>
                        <label for="proof" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                            Payment Proof
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(required)</span>
                        </label>
                        <textarea id="proof"
                                  name="proof"
                                  rows="6"
                                  required
                                  placeholder="Provide details of your payment proof (bank slip number, mobile money reference, screenshots, date and time of transfer, etc.)"
                                  class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm resize-none @error('proof') border-red-500 @enderror">{{ old('proof') }}</textarea>
                        @error('proof')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                        <a href="{{ route('reseller.invoices.show', $invoice) }}"
                           class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                            Cancel
                        </a>
                        <button type="submit" class="flex-1 px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                            Submit Payment Proof
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
                        <span class="text-sm font-medium text-slate-900 dark:text-white">KES {{ number_format($invoice->total, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Due Date</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->due_date->format('M d, Y') }}</span>
                    </div>
                </div>

                <div class="my-4 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        KES {{ number_format($invoice->total, 2) }}
                    </p>
                </div>

                <!-- Info -->
                <div class="p-3 bg-purple-50 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800 rounded-lg">
                    <p class="text-xs text-purple-900 dark:text-purple-300">
                        <strong>Status:</strong> Your submission will be reviewed by our admin team. You'll receive a notification once approved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
