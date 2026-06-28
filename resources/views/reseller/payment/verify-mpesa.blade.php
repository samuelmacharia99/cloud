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
            <p class="text-slate-600 dark:text-slate-400 mb-4">
                Invoice #{{ $invoice->invoice_number }} — KSH {{ number_format($amountDue, 2) }}
            </p>

            <div class="bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg p-4 mb-6">
                <p class="text-sm text-purple-900 dark:text-purple-100 mb-2">
                    <strong>A prompt has been sent to your M-Pesa phone number.</strong>
                </p>
                <p class="text-sm text-purple-800 dark:text-purple-200">
                    Please complete the transaction on your phone. We're verifying your payment...
                </p>
            </div>

            <div x-data="resellerMpesaVerify()" x-init="init()" class="space-y-4">
                <div x-show="checking" class="flex justify-center gap-2 my-8">
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

                <div x-show="!checking && !completed" class="flex gap-3 pt-4">
                    <a href="{{ route('reseller.invoices.show', $invoice) }}" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                        Back to Invoice
                    </a>
                    <a href="{{ route('reseller.payment.select-method', $invoice) }}" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-center">
                        Try Again
                    </a>
                </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-6">
                Auto-checking every 5 seconds for up to 5 minutes. You can close this window — payment will still be recorded.
            </p>
        </div>
    </div>
</div>

<script>
function resellerMpesaVerify() {
    return {
        checking: true,
        message: 'Waiting for payment confirmation...',
        completed: false,
        failed: false,
        checkoutRequestId: '{{ $checkoutRequestId }}',
        checkInterval: 5,
        maxAttempts: 60,
        pollTimer: null,

        init() {
            this.poll();
            this.pollTimer = setInterval(() => this.poll(), this.checkInterval * 1000);
        },

        async poll() {
            if (!this.checking) {
                return;
            }

            if (this.attempts >= this.maxAttempts) {
                this.checking = false;
                this.message = 'Verification timed out. If you completed payment, check your invoice in a few minutes.';
                clearInterval(this.pollTimer);

                return;
            }

            this.attempts = (this.attempts || 0) + 1;

            try {
                const statusUrl = new URL('{{ route('reseller.payment.mpesa-status', $invoice) }}', window.location.origin);
                statusUrl.searchParams.set('checkout_request_id', this.checkoutRequestId);
                const res = await fetch(statusUrl.toString());
                const data = await res.json();

                if (data.status === 'completed') {
                    this.checking = false;
                    this.completed = true;
                    this.message = 'Payment successful! Redirecting...';
                    clearInterval(this.pollTimer);
                    setTimeout(() => {
                        window.location.href = '{{ route('reseller.payment.success', $invoice) }}';
                    }, 2000);
                } else if (data.status === 'failed' || data.status === 'error') {
                    this.checking = false;
                    this.failed = true;
                    this.message = data.message || 'Payment was cancelled or failed';
                    clearInterval(this.pollTimer);
                } else {
                    this.message = data.message || 'Waiting for payment confirmation...';
                }
            } catch (e) {
                console.error('Verification error:', e);
            }
        },
    };
}
</script>
@endsection
