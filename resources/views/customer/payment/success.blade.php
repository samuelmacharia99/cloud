@extends('layouts.customer')

@section('title', 'Payment Successful')

@section('content')
<div class="space-y-6">
    <!-- Success Card -->
    <div class="max-w-md mx-auto">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 text-center">
            <!-- Success Icon -->
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/20 mb-6">
                <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
            </div>

            <!-- Message -->
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Payment Successful!</h1>
            <p class="text-slate-600 dark:text-slate-400 mb-6">Your payment has been received and processed.</p>

            <!-- Invoice Details -->
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4 mb-6 border border-slate-200 dark:border-slate-700 text-left">
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Invoice Number</span>
                        <span class="font-semibold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Amount Paid</span>
                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">Ksh {{ number_format($invoice->total, 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Paid At</span>
                        <span class="font-semibold text-slate-900 dark:text-white">{{ now()->format('M d, Y H:i') }}</span>
                    </div>
                </div>
            </div>

            <!-- Services Activation Info -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4 mb-6">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    <strong>✓ Services Activated:</strong> Your services are being provisioned and will be ready shortly. Check your dashboard for updates.
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="space-y-3">
                <a href="{{ route('dashboard') }}" class="block w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Go to Dashboard
                </a>
                <a href="{{ route('customer.invoices.show', $invoice) }}" class="block w-full px-4 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    View Invoice
                </a>
            </div>

            <!-- Next Steps -->
            <div class="mt-8 pt-6 border-t border-slate-200 dark:border-slate-700">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-3">What's Next?</h3>
                <ul class="text-sm text-slate-600 dark:text-slate-400 space-y-2 text-left">
                    <li>✓ Services are provisioning on your dedicated container</li>
                    <li>✓ You'll receive an email confirmation with connection details</li>
                    <li>✓ Access your services from your Dashboard</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
