@extends('layouts.customer')

@section('title', 'Payment Submitted')

@section('content')
<div class="space-y-6">
    <!-- Success Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border-2 border-emerald-200 dark:border-emerald-800 p-8">
        <div class="text-center">
            <!-- Success Icon -->
            <div class="flex justify-center mb-4">
                <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-900 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
            </div>

            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Payment Submitted Successfully</h1>
            <p class="text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                Thank you! Your payment details have been submitted for review. Our admin team will verify your payment and activate your services shortly.
            </p>
        </div>
    </div>

    <!-- Submission Details -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- What Happens Next -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">What Happens Next</h2>
                <div class="space-y-4">
                    <div class="flex gap-4">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center h-8 w-8 rounded-md bg-blue-100 dark:bg-blue-900">
                                <span class="text-blue-600 dark:text-blue-300 font-bold text-sm">1</span>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">Admin Review</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                Our admin team will verify your payment details within 1-24 hours (usually faster during business hours).
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center h-8 w-8 rounded-md bg-blue-100 dark:bg-blue-900">
                                <span class="text-blue-600 dark:text-blue-300 font-bold text-sm">2</span>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">Approval & Notification</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                Once approved, you'll receive an email confirmation and your invoice status will update to "Paid".
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center h-8 w-8 rounded-md bg-blue-100 dark:bg-blue-900">
                                <span class="text-blue-600 dark:text-blue-300 font-bold text-sm">3</span>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-medium text-slate-900 dark:text-white">Service Activation</h3>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                Your services will be automatically provisioned and activated. You can monitor the progress in your dashboard.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submission Information -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-4">Your Submission</h2>
                <div class="space-y-3">
                    @php
                        $notes = json_decode($payment->notes, true) ?? [];
                    @endphp

                    <div class="flex justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Payment ID</span>
                        <span class="text-sm font-mono text-slate-900 dark:text-white">{{ $payment->transaction_reference }}</span>
                    </div>

                    <div class="flex justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Invoice Number</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $payment->invoice->invoice_number }}</span>
                    </div>

                    <div class="flex justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Amount</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">Ksh {{ number_format($payment->amount, 0) }}</span>
                    </div>

                    <div class="flex justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Submitted At</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">{{ \Carbon\Carbon::parse($notes['submitted_at'] ?? now())->format('M d, Y H:i') }}</span>
                    </div>

                    <div class="flex justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Status</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300">
                            Pending Approval
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Quick Links -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sticky top-6 space-y-4">
                <h3 class="font-bold text-slate-900 dark:text-white">Quick Links</h3>

                <a href="{{ route('customer.invoices.show', $payment->invoice) }}"
                   class="w-full flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 rounded-lg transition font-medium text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 6H7a2 2 0 01-2-2V7a2 2 0 012-2h10a2 2 0 012 2v6a2 2 0 01-2 2H7m0 0V9m0 10v2"/>
                    </svg>
                    View Invoice
                </a>

                <a href="{{ route('customer.services.index') }}"
                   class="w-full flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 rounded-lg transition font-medium text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    My Services
                </a>

                <!-- Info Box -->
                <div class="p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                    <p class="text-xs text-blue-900 dark:text-blue-300">
                        <strong>Need help?</strong> If you don't see your payment approved within 24 hours, please contact our support team.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
