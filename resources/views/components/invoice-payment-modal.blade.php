@props([
    'invoice',
    'fetchUrl',
    'submitUrl',
    'context' => 'customer',
    'amountDue' => 0,
    'walletBalance' => 0,
    'allowWalletApply' => false,
    'buttonLabel' => 'Pay Now',
    'buttonClass' => 'inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition',
])

<div x-data="{
    showPaymentModal: false,
    loadingGateways: false,
    gateways: {},
    selectedGateway: '',
    mpesaPhone: '',
    submitting: false,
    applyWallet: false,
    walletBalance: {{ (float) $walletBalance }},
    amountDue: {{ (float) $amountDue }},
    get walletToApply() {
        if (!{{ $allowWalletApply ? 'true' : 'false' }} || !this.applyWallet) {
            return 0;
        }
        return Math.min(this.walletBalance, this.amountDue);
    },
    get payableAmount() {
        return Math.max(0, this.amountDue - this.walletToApply);
    },
    async openPaymentModal() {
        this.showPaymentModal = true;
        this.loadingGateways = true;
        this.selectedGateway = '';
        this.mpesaPhone = '';
        this.applyWallet = false;

        try {
            const res = await fetch('{{ $fetchUrl }}', {
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) {
                throw new Error('Failed to load payment methods');
            }

            const data = await res.json();
            this.gateways = data.gateways || {};
            this.walletBalance = data.wallet_balance ?? this.walletBalance;
            this.amountDue = data.amount_due ?? this.amountDue;

            if (Object.keys(this.gateways).length > 0) {
                this.selectedGateway = Object.keys(this.gateways)[0];
            }
        } catch (error) {
            alert(error.message || 'Unable to load payment methods.');
            this.showPaymentModal = false;
        } finally {
            this.loadingGateways = false;
        }
    },
    closePaymentModal() {
        this.showPaymentModal = false;
    },
    submitPayment() {
        if (!this.selectedGateway && this.payableAmount > 0) {
            alert('Please select a payment method.');
            return;
        }

        if (this.payableAmount <= 0 && this.applyWallet && {{ $allowWalletApply ? 'true' : 'false' }}) {
            this.selectedGateway = 'wallet';
        }

        this.submitting = true;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ $submitUrl }}';

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = document.querySelector('meta[name=csrf-token]').content;
        form.appendChild(csrfInput);

        const methodInput = document.createElement('input');
        methodInput.type = 'hidden';
        methodInput.name = '{{ $context === 'reseller' ? 'method' : 'payment_method' }}';
        methodInput.value = this.selectedGateway;
        form.appendChild(methodInput);

        if (this.selectedGateway === 'mpesa' && this.mpesaPhone) {
            const phoneInput = document.createElement('input');
            phoneInput.type = 'hidden';
            phoneInput.name = 'phone';
            phoneInput.value = this.mpesaPhone;
            form.appendChild(phoneInput);
        }

        if ({{ $allowWalletApply ? 'true' : 'false' }} && this.applyWallet) {
            const walletInput = document.createElement('input');
            walletInput.type = 'hidden';
            walletInput.name = 'apply_wallet';
            walletInput.value = '1';
            form.appendChild(walletInput);
        }

        document.body.appendChild(form);
        form.submit();
    }
}">
    <button type="button" @click="openPaymentModal()" class="{{ $buttonClass }}">
        {{ $buttonLabel }}
    </button>

    <div x-show="showPaymentModal" x-transition class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" @click.self="closePaymentModal()">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 max-w-lg w-full mx-4">
            <div class="flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-700">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Select Payment Method</h3>
                <button type="button" @click="closePaymentModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 space-y-4">
                @if($allowWalletApply)
                <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm font-medium text-emerald-900 dark:text-emerald-200">Wallet Balance</p>
                        <p class="text-lg font-bold text-emerald-700 dark:text-emerald-300">KSH <span x-text="walletBalance.toFixed(2)"></span></p>
                    </div>
                    <template x-if="walletBalance > 0 && amountDue > 0">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" x-model="applyWallet" class="mt-1 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                            <span class="text-sm text-slate-700 dark:text-slate-300">
                                Apply wallet balance
                                <span class="block text-xs text-slate-500 dark:text-slate-400 mt-1" x-show="applyWallet">
                                    KSH <span x-text="walletToApply.toFixed(2)"></span> from wallet · Pay KSH <span x-text="payableAmount.toFixed(2)"></span> via selected method
                                </span>
                            </span>
                        </label>
                    </template>
                </div>
                @endif

                <template x-if="loadingGateways">
                    <div class="flex items-center justify-center py-8">
                        <div class="w-5 h-5 bg-purple-600 rounded-full animate-bounce"></div>
                    </div>
                </template>

                <template x-if="!loadingGateways && Object.keys(gateways).length > 0">
                    <form @submit.prevent="submitPayment()">
                        <div class="space-y-3" x-show="payableAmount > 0">
                            <template x-for="(entry, index) in Object.entries(gateways)" :key="index">
                                <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition" :class="selectedGateway === entry[0] ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-700 hover:border-purple-300'">
                                    <input type="radio" :value="entry[0]" x-model="selectedGateway" class="w-5 h-5 mt-1 rounded-full border-slate-300 text-purple-600 focus:ring-0 focus:border-purple-500 transition">
                                    <div class="ml-4 flex-1">
                                        <p class="font-semibold text-slate-900 dark:text-white" x-text="entry[1].label"></p>
                                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="entry[1].description"></p>
                                    </div>
                                </label>
                            </template>
                        </div>

                        <div x-show="payableAmount <= 0 && applyWallet && {{ $allowWalletApply ? 'true' : 'false' }}" class="p-4 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg text-sm text-emerald-800 dark:text-emerald-200">
                            Your wallet will cover the full amount due. Click continue to complete payment.
                        </div>

                        <template x-if="selectedGateway === 'mpesa' && payableAmount > 0">
                            <div class="mt-4 p-4 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                                <input type="tel" placeholder="254712345678" x-model="mpesaPhone" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Format: 254XXXXXXXXX</p>
                            </div>
                        </template>

                        <div class="flex gap-3 mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                            <button type="button" @click="closePaymentModal()" class="flex-1 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition">
                                Cancel
                            </button>
                            <button type="submit" :disabled="submitting" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-slate-400 text-white font-medium rounded-lg transition">
                                <span x-show="!submitting">Continue to Payment</span>
                                <span x-show="submitting">Processing...</span>
                            </button>
                        </div>
                    </form>
                </template>

                <template x-if="!loadingGateways && Object.keys(gateways).length === 0">
                    <div class="text-center py-8">
                        <p class="text-slate-600 dark:text-slate-400">No payment methods available. Please contact support.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
