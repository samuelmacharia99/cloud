@extends('layouts.reseller')

@section('title', 'My Wallet')

@section('content')
<div class="space-y-6">
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
                <strong>Low Balance Alert:</strong> Your wallet balance is below KES {{ number_format($wallet->low_balance_threshold, 2) }}. Top up to process domain orders.
            </p>
        </div>
        @endif
    </div>

    <!-- Top-up Form (Alpine.js) -->
    <div x-data="{ openTopupForm: false, loading: false, invoiceId: null, checkoutId: null, checking: false }" @click.outside="openTopupForm = false">
        <div x-show="openTopupForm" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 w-full max-w-md">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Top Up Wallet</h3>
                    <button type="button" @click="openTopupForm = false" class="text-slate-500 hover:text-slate-700">✕</button>
                </div>

                <form @submit.prevent="async () => {
                    loading = true;
                    const formData = new FormData(this);
                    const response = await fetch('{{ route('reseller.wallet.topup') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success) {
                        invoiceId = data.invoice_id;
                        checkoutId = data.checkout_request_id;
                        startStatusCheck();
                    } else {
                        alert('Error: ' + data.message);
                        loading = false;
                    }
                }">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (KES)</label>
                            <input type="number" name="amount" min="1500" max="50000" step="100" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400">
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Minimum: KES 1,500 | Maximum: KES 50,000</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-Pesa Phone</label>
                            <input type="tel" name="phone" placeholder="+254712345678" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400">
                        </div>

                        <button type="submit" :disabled="loading" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <span x-show="!loading">Initiate Payment</span>
                            <span x-show="loading">Processing...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Confirmation (shown after STK push) -->
        <div x-show="checkoutId && !checking" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6" style="display: none;">
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-2">M-Pesa Payment Initiated</h3>
            <p class="text-blue-800 dark:text-blue-300 mb-4">Check your phone for the M-Pesa prompt. Do not refresh this page.</p>
            <button type="button" @click="startStatusCheck()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg">
                Verify Payment
            </button>
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
                                    'domain_debit' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
                                    'refund' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
                                    'adjustment' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
                                    default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-slate-900 dark:text-white">{{ $transaction->description }}</td>
                            <td class="px-6 py-3 text-right text-sm font-medium {{ $transaction->type === 'domain_debit' ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ $transaction->type === 'domain_debit' ? '-' : '+' }}KES {{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-6 py-3 text-right text-sm text-slate-900 dark:text-white">KES {{ number_format($transaction->balance_after, 2) }}</td>
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

<script>
function startStatusCheck() {
    const invoiceId = this.invoiceId;
    const checkoutId = this.checkoutId;
    this.checking = true;

    const interval = setInterval(async () => {
        const response = await fetch(`{{ route('reseller.wallet.topup.status', '') }}/${invoiceId}`);
        const data = await response.json();

        if (data.status === 'completed') {
            clearInterval(interval);
            alert('Payment successful! Your wallet has been credited.');
            window.location.reload();
        } else if (data.status === 'failed') {
            clearInterval(interval);
            alert('Payment failed: ' + data.message);
            window.location.reload();
        }
    }, 2000);
}
</script>
@endsection
