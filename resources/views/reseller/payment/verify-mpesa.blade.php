@extends('layouts.reseller')

@section('title', 'Verify M-Pesa Payment')

@section('content')
<div class="space-y-6 max-w-2xl mx-auto">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="text-center">
            <div class="mb-6">
                <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center mx-auto">
                    <svg class="w-8 h-8 text-purple-600 dark:text-purple-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Verify Your M-Pesa Payment</h1>
            <p class="text-slate-600 dark:text-slate-400 mb-4">Invoice #{{ $invoice->invoice_number }} - KSH {{ number_format($invoice->total, 2) }}</p>

            <div class="bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg p-4 mb-6">
                <p class="text-sm text-purple-900 dark:text-purple-100 mb-2">
                    <strong>A prompt has been sent to your M-Pesa phone number.</strong>
                </p>
                <p class="text-sm text-purple-800 dark:text-purple-200">
                    Please complete the transaction on your phone. We're verifying your payment...
                </p>
            </div>

            <div x-data="{ checking: true, message: 'Waiting for payment confirmation...', completed: false, failed: false, checkoutRequestId: '{{ $checkoutRequestId }}' }"
                 x-init="async function() {
                     let attempts = 0;
                     const maxAttempts = 120; // 2 minutes
                     while (this.checking && attempts < maxAttempts) {
                         try {
                             const statusUrl = new URL('{{ route('reseller.payment.mpesa-status', $invoice) }}', window.location.origin);
                             statusUrl.searchParams.set('checkout_request_id', this.checkoutRequestId);
                             const res = await fetch(statusUrl.toString());
                             const data = await res.json();

                             if (data.status === 'completed') {
                                 this.checking = false;
                                 this.completed = true;
                                 this.message = 'Payment successful! Redirecting...';
                                 setTimeout(() => {
                                     window.location.href = '{{ route('reseller.payment.success', $invoice) }}';
                                 }, 2000);
                             } else if (data.status === 'failed') {
                                 this.checking = false;
                                 this.failed = true;
                                 this.message = data.message || 'Payment was cancelled or failed';
                             } else if (data.status === 'error') {
                                 this.checking = false;
                                 this.failed = true;
                                 this.message = 'Payment verification failed: ' + (data.message || 'Unknown error');
                             }
                         } catch (e) {
                             console.error('Verification error:', e);
                         }

                         attempts++;
                         await new Promise(r => setTimeout(r, 1000));
                     }

                     if (this.checking) {
                         this.checking = false;
                         this.message = 'Payment verification timed out. Please check your account.';
                     }
                 }()"
                 class="space-y-4">

                <div v-if="checking" class="flex justify-center gap-2 my-8">
                    <div class="w-3 h-3 bg-purple-600 rounded-full animate-bounce" style="animation-delay: 0s;"></div>
                    <div class="w-3 h-3 bg-purple-600 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                    <div class="w-3 h-3 bg-purple-600 rounded-full animate-bounce" style="animation-delay: 0.4s;"></div>
                </div>

                <p :class="{
                    'text-green-700 dark:text-green-300': completed,
                    'text-slate-600 dark:text-slate-400': !completed && checking,
                    'text-red-700 dark:text-red-300': failed
                }" class="text-sm font-medium" x-text="message">
                </p>

                <div v-if="!checking && !completed" class="flex gap-3 pt-4">
                    <a href="{{ route('reseller.invoices.show', $invoice) }}" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                        Back to Invoice
                    </a>
                    <a href="{{ route('reseller.payment.select-method', $invoice) }}" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-center">
                        Try Again
                    </a>
                </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-6">
                This page will automatically update when payment is received. You can close this window if needed.
            </p>
        </div>
    </div>
</div>
@endsection
