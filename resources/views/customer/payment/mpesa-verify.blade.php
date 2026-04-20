@extends('layouts.customer')

@section('title', 'Verify M-PESA Payment')

@section('content')
<div class="space-y-6" x-data="mpesaVerify()">
    <!-- Header -->
    <div class="text-center">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">M-PESA Payment Verification</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice #{{ $invoice->invoice_number }}</p>
    </div>

    <!-- Main Verification Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 max-w-md mx-auto">
        <!-- Animated Loader -->
        <div class="text-center mb-8">
            <!-- Pulse Circle -->
            <div class="relative inline-flex items-center justify-center w-24 h-24 mb-4">
                <div class="absolute inset-0 bg-green-400/20 rounded-full animate-pulse"></div>
                <div class="absolute inset-2 bg-green-400/10 rounded-full animate-pulse" style="animation-delay: 0.3s;"></div>
                <div class="relative flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-green-500 to-emerald-600">
                    <svg class="animate-spin h-10 w-10 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </div>

            <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Verifying Payment</h2>
            <p class="text-slate-600 dark:text-slate-400 text-sm">{{ $message }}</p>
        </div>

        <!-- Timer -->
        <div class="text-center mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg">
            <p class="text-xs text-green-700 dark:text-green-300 mb-1">Checking payment status in:</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400" x-text="'0:' + String(nextCheck).padStart(2, '0')"></p>
        </div>

        <!-- Instructions -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2z" clip-rule="evenodd"/>
                </svg>
                Steps to Complete Payment:
            </h3>
            <ol class="text-sm text-blue-800 dark:text-blue-200 space-y-2">
                <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex-shrink-0">1</span>
                    <span>Check your phone for the M-PESA STK prompt</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex-shrink-0">2</span>
                    <span>Enter your 4-digit M-PESA PIN when prompted</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex-shrink-0">3</span>
                    <span>Wait for confirmation (this page updates automatically)</span>
                </li>
            </ol>
        </div>

        <!-- Amount Due -->
        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-6 mb-6 border border-slate-200 dark:border-slate-700">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Amount to Pay</p>
            <p class="text-4xl font-bold text-slate-900 dark:text-white">Ksh {{ number_format($invoice->total, 0) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Invoice: {{ $invoice->invoice_number }}</p>
        </div>

        <!-- Status Info -->
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg p-3 mb-6">
            <p class="text-xs text-amber-700 dark:text-amber-300">
                <strong>⏱ Auto-checking every 5 seconds.</strong> You will be automatically redirected once payment is confirmed. Do not close this page.
            </p>
        </div>

        <!-- Manual Check Button -->
        <button @click="checkPayment()" :disabled="checking" class="w-full px-4 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-medium transition mb-4">
            <span x-show="!checking" class="inline-flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 1119.414 9.414 1 1 0 11-1.414-1.414A5.002 5.002 0 005.659 5.659V5a1 1 0 011-1h2a1 1 0 001-1V2a1 1 0 00-1-1H4zm9 11a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                Check Payment Now
            </span>
            <span x-show="checking" class="inline-flex items-center justify-center gap-2">
                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Checking...
            </span>
        </button>

        <!-- Cancel Link -->
        <p class="text-center">
            <a href="{{ route('customer.payment.select-method', $invoice) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:underline">
                ← Choose a different payment method
            </a>
        </p>
    </div>
</div>

<script>
function mpesaVerify() {
    return {
        checking: false,
        checkoutRequestId: '{{ $checkout_request_id }}',
        invoiceId: {{ $invoice->id }},
        autoCheckInterval: null,
        timerInterval: null,
        nextCheck: 0,
        checkInterval: 5,

        async init() {
            // Start auto-check every 5 seconds
            await this.checkPayment();
            this.autoCheckInterval = setInterval(() => this.checkPayment(), this.checkInterval * 1000);

            // Update timer display
            this.timerInterval = setInterval(() => {
                this.nextCheck--;
                if (this.nextCheck < 0) {
                    this.nextCheck = this.checkInterval;
                }
            }, 1000);
        },

        async checkPayment() {
            if (this.checking) return;

            this.checking = true;
            this.nextCheck = this.checkInterval;

            try {
                const res = await fetch(`{{ route('customer.payment.mpesa-status', $invoice) }}?checkout_request_id=${this.checkoutRequestId}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await res.json();

                if (data.status === 'completed') {
                    // Clear intervals before redirect
                    this.destroy();
                    // Show success message briefly before redirect
                    const successMsg = document.createElement('div');
                    successMsg.className = 'fixed inset-0 flex items-center justify-center bg-black/50 z-50';
                    successMsg.innerHTML = `
                        <div class="bg-white dark:bg-slate-900 rounded-xl p-8 text-center max-w-sm">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/20 mb-4">
                                <svg class="w-8 h-8 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Payment Successful!</h2>
                            <p class="text-slate-600 dark:text-slate-400 mb-4">Your payment has been confirmed. Redirecting...</p>
                        </div>
                    `;
                    document.body.appendChild(successMsg);
                    setTimeout(() => {
                        window.location.href = '{{ route("customer.payment.success", $invoice) }}';
                    }, 1500);
                } else if (data.status === 'failed') {
                    // Clear intervals before redirect
                    this.destroy();
                    window.location.href = '{{ route("customer.payment.select-method", $invoice) }}?error=payment_failed';
                }
                // 'pending' → keep polling
            } catch (error) {
                console.error('Payment status check failed:', error);
            } finally {
                this.checking = false;
            }
        },

        destroy() {
            if (this.autoCheckInterval) {
                clearInterval(this.autoCheckInterval);
            }
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
            }
        }
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
    const data = mpesaVerify();
    data.init();
    window.addEventListener('beforeunload', () => data.destroy());
});
</script>
@endsection
