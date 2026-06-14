@extends('layouts.customer')

@section('title', 'Account Credits')

@section('content')
<div class="space-y-6" x-data="{
    openTopupForm: false,
    loading: false,
    invoiceId: null,
    checkoutId: null,
    checking: false,
    async startStatusCheck() {
        this.checking = true;
        const invoiceId = this.invoiceId;
        const interval = setInterval(async () => {
            try {
                const response = await fetch(`{{ route('customer.credits.topup.status', '') }}/${invoiceId}`);
                const data = await response.json();

                if (data.status === 'completed') {
                    clearInterval(interval);
                    alert('Payment successful! Credits have been added to your account.');
                    setTimeout(() => window.location.reload(), 500);
                } else if (data.status === 'failed') {
                    clearInterval(interval);
                    alert('Payment failed: ' + data.message);
                    setTimeout(() => window.location.reload(), 500);
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
            }
        }, 2000);
    }
}" @click.outside="if (event.target.closest('.modal-form')) { return; } openTopupForm = false">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Account Credits</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Fund your account and use credits at checkout.</p>
        </div>
        <button type="button" @click="openTopupForm = true" class="btn-primary">
            + Buy Credits
        </button>
    </div>

    @if (session('success'))
        <div class="ui-card p-4 border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-950 dark:to-emerald-900 rounded-xl border border-emerald-200 dark:border-emerald-800 p-8">
        <div class="text-center">
            <p class="text-emerald-700 dark:text-emerald-300 text-sm font-medium mb-2">Available Balance</p>
            <h2 class="text-5xl font-bold text-emerald-900 dark:text-emerald-100 mb-2">KES {{ number_format($availableBalance, 2) }}</h2>
            <p class="text-sm text-emerald-700 dark:text-emerald-300">{{ $activeCredits->count() }} active credit {{ Str::plural('entry', $activeCredits->count()) }}</p>
        </div>
    </div>

    <!-- Top-up Modal -->
    <div x-show="openTopupForm" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="modal-form ui-card p-6 w-full max-w-md max-h-screen overflow-y-auto" x-data="{ paymentMethod: 'mpesa' }">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Buy Account Credits</h3>
                <button type="button" @click="openTopupForm = false" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300">✕</button>
            </div>

            <form @submit.prevent="async function(e) {
                loading = true;
                const form = e.target;
                const formData = new FormData(form);
                try {
                    const response = await fetch('{{ route('customer.credits.topup') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success) {
                        if (data.checkout_url) {
                            window.location.href = data.checkout_url;
                            return;
                        }
                        if (data.approval_url) {
                            window.location.href = data.approval_url;
                            return;
                        }
                        invoiceId = data.invoice_id;
                        checkoutId = data.checkout_request_id;
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
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (KES)</label>
                        <input type="number" name="amount" min="5" max="50000" step="1" required class="form-input w-full">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Minimum: KES 5 | Maximum: KES 50,000</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Payment Method</label>
                        <div class="space-y-2">
                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-emerald-500 dark:hover:border-emerald-400 cursor-pointer transition" @click="paymentMethod = 'mpesa'">
                                <input type="radio" name="payment_method" value="mpesa" x-model="paymentMethod" class="w-4 h-4 text-emerald-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">M-Pesa</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Instant payment via M-Pesa</p>
                                </div>
                            </label>

                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-emerald-500 dark:hover:border-emerald-400 cursor-pointer transition" @click="paymentMethod = 'stripe'">
                                <input type="radio" name="payment_method" value="stripe" x-model="paymentMethod" class="w-4 h-4 text-emerald-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">Card</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Pay with credit or debit card</p>
                                </div>
                            </label>

                            <label class="flex items-center p-3 border-2 border-slate-300 dark:border-slate-600 rounded-lg hover:border-emerald-500 dark:hover:border-emerald-400 cursor-pointer transition" @click="paymentMethod = 'paypal'">
                                <input type="radio" name="payment_method" value="paypal" x-model="paymentMethod" class="w-4 h-4 text-emerald-600">
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">PayPal</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Pay via PayPal</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div x-show="paymentMethod === 'mpesa'" class="p-4 bg-emerald-50 dark:bg-emerald-950/20 rounded-lg border border-emerald-200 dark:border-emerald-800">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-Pesa Phone Number</label>
                        <input type="tel" name="phone" placeholder="+254712345678" :required="paymentMethod === 'mpesa'" class="form-input w-full">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Enter your M-Pesa registered phone number</p>
                    </div>

                    <button type="submit" :disabled="loading" class="btn-primary w-full">
                        <span x-show="!loading">Proceed to Payment</span>
                        <span x-show="loading">Processing...</span>
                    </button>
                </div>
            </form>

            <div x-show="checkoutId && !checking" class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl" style="display: none;">
                <div class="flex items-start gap-3 mb-3">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-blue-900 dark:text-blue-200">M-Pesa Payment Initiated</h4>
                        <p class="text-sm text-blue-800 dark:text-blue-300 mt-1">Check your phone for the M-Pesa prompt. Do not refresh or close this page.</p>
                    </div>
                </div>
                <button type="button" @click="startStatusCheck()" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition">
                    Verify Payment
                </button>
            </div>
        </div>
    </div>

    <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Status</label>
            <select name="status" class="form-select text-sm">
                <option value="all" @selected(request('status', 'all') === 'all')>All</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="applied" @selected(request('status') === 'applied')>Applied</option>
                <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                <option value="refunded" @selected(request('status') === 'refunded')>Refunded</option>
            </select>
        </div>
        <button type="submit" class="btn-primary btn-sm">Filter</button>
        @if(request()->hasAny(['status']))
            <a href="{{ route('customer.credits.index') }}" class="btn-secondary btn-sm">Clear</a>
        @endif
    </form>

    <div class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Date</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Amount</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Source</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Expires</th>
                        <th class="px-6 py-3 text-left text-sm font-semibold">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($credits as $credit)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-6 py-4 text-sm">{{ $credit->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-sm font-medium">KES {{ number_format($credit->amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm capitalize">{{ str_replace('_', ' ', $credit->source) }}</td>
                            <td class="px-6 py-4 text-sm capitalize">{{ $credit->status }}</td>
                            <td class="px-6 py-4 text-sm">{{ $credit->expires_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ Str::limit($credit->notes, 40) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500">No credits on your account yet. Buy credits to get started.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($credits->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $credits->links() }}</div>
        @endif
    </div>
</div>
@endsection
