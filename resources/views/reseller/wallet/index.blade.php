@extends('layouts.reseller')

@section('title', 'My Wallet')

@section('content')
<div class="space-y-6" x-data="{
    openTopupForm: false,
    loading: false,
    invoiceId: null,
    checkoutId: null,
    checking: false,
    statusMessage: '',
    async startStatusCheck() {
        if (!this.invoiceId) return;
        this.checking = true;
        this.loading = false;
        this.statusMessage = 'Waiting for payment confirmation...';
        const invoiceId = this.invoiceId;
        const self = this;
        const startedAt = Date.now();

        const pollIntervalMs = 5000;
        const maxDurationMs = 300000;

        const interval = setInterval(async () => {
            try {
                const response = await fetch(`{{ url('reseller/wallet/topup/status') }}/${invoiceId}`);
                const data = await response.json();

                if (data.status === 'completed') {
                    clearInterval(interval);
                    self.checking = false;
                    alert('Payment successful! Your wallet has been credited.');
                    setTimeout(() => window.location.reload(), 500);
                } else if (data.status === 'failed') {
                    clearInterval(interval);
                    self.checking = false;
                    alert('Payment failed: ' + data.message);
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    self.statusMessage = data.message || 'Transaction still processing on M-Pesa.';
                }

                if (Date.now() - startedAt > maxDurationMs) {
                    clearInterval(interval);
                    self.checking = false;
                    self.statusMessage = 'Still processing. If you completed payment, your wallet will update shortly.';
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
            }
        }, pollIntervalMs);
    }
}" @click.outside="if (event.target.closest('.modal-form')) { return; } openTopupForm = false">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Wallet</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your reseller wallet and domain credits.</p>
        </div>
    </div>

    <!-- Balance Card -->
    <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-8">
        <div class="text-center">
            <p class="text-purple-700 dark:text-purple-300 text-sm font-medium mb-2">Available Balance</p>
            <h2 class="text-5xl font-bold text-purple-900 dark:text-purple-100 mb-4">{{ $wallet->getFormattedBalance() }}</h2>
            <div class="flex items-center justify-center gap-4">
                <button type="button" @click="openTopupForm = true" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                    + Top Up
                </button>
                <a href="{{ route('reseller.wallet.transactions') }}" class="px-6 py-2 bg-white dark:bg-slate-800 border border-purple-200 dark:border-purple-700 text-purple-600 dark:text-purple-400 font-medium rounded-lg hover:bg-purple-50 dark:hover:bg-slate-700 transition">
                    View History
                </a>
            </div>
        </div>

        @if($wallet->isLowBalance())
        <div class="mt-6 p-4 bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 rounded-lg">
            <p class="text-amber-800 dark:text-amber-200 text-sm">
                <strong>Low Balance Alert:</strong> Your wallet balance is below KSH {{ number_format($wallet->low_balance_threshold, 2) }}. Top up to process domain orders.
            </p>
        </div>
        @endif
    </div>

    <!-- Top-up Modal Overlay -->
    <div x-show="openTopupForm" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <!-- Top-up Form Card -->
        <div class="modal-form bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 w-full max-w-md max-h-screen overflow-y-auto" x-data="{ paymentMethod: 'mpesa' }">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Top Up Wallet</h3>
                <button type="button" @click="openTopupForm = false" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300">✕</button>
            </div>

            <form @submit.prevent="async function(e) {
                loading = true;
                const form = e.target;
                const formData = new FormData(form);
                try {
                    const response = await fetch('{{ route('reseller.wallet.topup') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success) {
                        invoiceId = data.invoice_id;
                        checkoutId = data.checkout_request_id;
                        statusMessage = data.message || 'Check your phone for M-Pesa prompt.';
                        startStatusCheck();
                    } else {
                        alert('Error: ' + data.message);
                        loading = false;
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    loading = false;
                }
            }">
                @csrf
                <div class="space-y-4">
                    <!-- Amount -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (KSH)</label>
                        <input type="number" name="amount" min="5" max="50000" step="1" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Minimum: KSH 5 | Maximum: KSH 50,000</p>
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Payment Method</label>
                        <div class="space-y-2">
                            <!-- M-Pesa -->
                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-purple-500 dark:hover:border-purple-400 cursor-pointer transition" @click="paymentMethod = 'mpesa'">
                                <input type="radio" name="payment_method" value="mpesa" x-model="paymentMethod" class="w-4 h-4 text-purple-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">M-Pesa</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Instant payment via M-Pesa</p>
                                </div>
                            </label>

                            <!-- Card (Stripe) -->
                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-purple-500 dark:hover:border-purple-400 cursor-pointer transition" @click="paymentMethod = 'stripe'">
                                <input type="radio" name="payment_method" value="stripe" x-model="paymentMethod" class="w-4 h-4 text-purple-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">Card</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Pay with credit/debit card</p>
                                </div>
                            </label>

                            <!-- PayPal -->
                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-purple-500 dark:hover:border-purple-400 cursor-pointer transition" @click="paymentMethod = 'paypal'">
                                <input type="radio" name="payment_method" value="paypal" x-model="paymentMethod" class="w-4 h-4 text-purple-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">PayPal</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Pay via PayPal</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- M-Pesa Phone (conditional) -->
                    <div x-show="paymentMethod === 'mpesa'" class="p-4 bg-purple-50 dark:bg-purple-950/20 rounded-lg border border-purple-200 dark:border-purple-800">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-Pesa Phone Number</label>
                        <input type="tel" name="phone" placeholder="+254712345678" x-show="paymentMethod === 'mpesa'" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Enter your M-Pesa registered phone number</p>
                    </div>

                    <button type="submit" :disabled="loading" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition">
                        <span x-show="!loading">Proceed to Payment</span>
                        <span x-show="loading">Processing...</span>
                    </button>
                </div>
            </form>

            <!-- Payment Confirmation (shown after STK push) -->
            <div x-show="checkoutId" class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl" style="display: none;">
                <div class="flex items-start gap-3 mb-3">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-blue-900 dark:text-blue-200">M-Pesa Payment Initiated</h4>
                        <p class="text-sm text-blue-800 dark:text-blue-300 mt-1" x-text="statusMessage || 'Check your phone for the M-Pesa prompt. Do not refresh or close this page.'"></p>
                    </div>
                </div>
                <button type="button" @click="startStatusCheck()" :disabled="checking" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg text-sm transition">
                    Verify Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Transactions</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Type</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Description</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-slate-900 dark:text-white">Balance</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($recentTransactions as $transaction)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($transaction->type) {
                                    'deposit' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
                                    'domain_debit', 'subscription_debit' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    'refund' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                    'adjustment' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-slate-900 dark:text-white">{{ $transaction->description }}</td>
                            <td class="px-6 py-3 text-right text-sm font-medium {{ $transaction->isDebit() ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ $transaction->isDebit() ? '-' : '+' }}KSH {{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-6 py-3 text-right text-sm text-slate-900 dark:text-white">KSH {{ number_format($transaction->balance_after, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-slate-600 dark:text-slate-400">{{ $transaction->created_at->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">No transactions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Queued Orders Summary -->
    @if($queuedOrdersCount > 0)
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <div>
                <p class="font-semibold text-amber-900 dark:text-amber-200">{{ $queuedOrdersCount }} Domain Order(s) Pending</p>
                <p class="text-sm text-amber-800 dark:text-amber-300">These orders will be automatically processed when your wallet has sufficient funds.</p>
            </div>
            <a href="{{ route('reseller.domain-orders.index') }}" class="ml-auto px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                View Orders
            </a>
        </div>
    </div>
    @endif
</div>

@endsection
