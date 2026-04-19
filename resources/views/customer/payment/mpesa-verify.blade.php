@extends('layouts.customer')

@section('title', 'Verify M-PESA Payment')

@section('content')
<div class="space-y-6" x-data="mpesaVerify()">
    <!-- Header -->
    <div class="text-center">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">M-PESA Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice #{{ $invoice->invoice_number }}</p>
    </div>

    <!-- Verification Status -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 max-w-md mx-auto">
        <!-- Checking Animation -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-900/20 mb-4">
                <svg class="animate-spin h-8 w-8 text-amber-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>

            <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Waiting for Payment</h2>
            <p class="text-slate-600 dark:text-slate-400">{{ $message }}</p>
        </div>

        <!-- Instructions -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">What to do:</h3>
            <ol class="text-sm text-blue-800 dark:text-blue-200 space-y-1 list-decimal list-inside">
                <li>Check your phone for the M-PESA prompt</li>
                <li>Enter your M-PESA PIN when prompted</li>
                <li>Wait for confirmation (this page will update automatically)</li>
            </ol>
        </div>

        <!-- Amount Due -->
        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4 mb-6 border border-slate-200 dark:border-slate-700">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Amount Due</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white">Ksh {{ number_format($invoice->total, 0) }}</p>
        </div>

        <!-- Auto-Refresh Info -->
        <p class="text-xs text-slate-500 dark:text-slate-400 text-center mb-4">
            This page checks for payment automatically every 5 seconds. You'll be redirected when payment is confirmed.
        </p>

        <!-- Manual Check Button -->
        <button @click="checkPayment()" :disabled="checking" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-medium transition">
            <span x-show="!checking">Check Payment Status</span>
            <span x-show="checking" class="inline-flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Checking...
            </span>
        </button>

        <!-- Cancel Link -->
        <p class="text-center mt-4">
            <a href="{{ route('customer.payment.select-method', $invoice) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                Choose different payment method
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

        async init() {
            // Auto-check every 5 seconds
            this.autoCheckInterval = setInterval(() => this.checkPayment(), 5000);
        },

        async checkPayment() {
            if (this.checking) return;

            this.checking = true;

            try {
                const res = await fetch(`{{ route('customer.payment.mpesa-status', $invoice) }}?checkout_request_id=${this.checkoutRequestId}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await res.json();

                if (data.status === 'completed') {
                    window.location.href = '{{ route("customer.payment.success", $invoice) }}';
                } else if (data.status === 'failed') {
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
