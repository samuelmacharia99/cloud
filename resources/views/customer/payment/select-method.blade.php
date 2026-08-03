@extends('layouts.customer')

@section('title', 'Select Payment Method')

@section('content')
@php
    $amountDue = $amountRemaining ?? $invoice->getAmountRemaining();
    $defaultGateway = array_key_first($availableGateways ?? []) ?: 'mpesa';
    $customerPhone = old('phone', auth()->user()->phone ?? '');
@endphp

<div
    class="space-y-6"
    x-data="{
        selectedMethod: @js($defaultGateway),
        mpesaPhoneNumber: @js($customerPhone),
        agreeTerms: false,
        showManualModal: false,
        ctaLabel() {
            return {
                mpesa: 'Send M-Pesa prompt',
                stripe: 'Continue to Stripe',
                paypal: 'Continue to PayPal',
                manual: 'Enter payment details',
                bank_transfer: 'Continue to bank transfer',
            }[this.selectedMethod] || 'Continue to payment';
        },
        canPay() {
            if (! this.agreeTerms || ! this.selectedMethod) {
                return false;
            }
            if (this.selectedMethod === 'mpesa' && ! String(this.mpesaPhoneNumber || '').trim()) {
                return false;
            }
            return true;
        }
    }"
>
    <div class="space-y-3">
        <x-checkout.steps current="pay" class="max-w-xl" />
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Pay invoice</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                Invoice #{{ $invoice->invoice_number }} — choose how you want to pay.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif
    @if (session('info'))
        <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 px-4 py-3 text-sm text-blue-800 dark:text-blue-200">
            {{ session('info') }}
        </div>
    @endif

    @if (($creditBalance ?? 0) > 0 && $amountDue > 0)
        <div class="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 rounded-xl p-5 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="font-semibold text-emerald-900 dark:text-emerald-300">Account credit available</p>
                <p class="text-sm text-emerald-800 dark:text-emerald-400 mt-1">
                    <x-currency-formatter :amount="$creditBalance" :convertFromKES="true" /> can be applied to this invoice.
                </p>
            </div>
            <form method="POST" action="{{ route('customer.payment.apply-credits', $invoice) }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg text-sm">Apply credits</button>
            </form>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Payment method</h2>
                    @if ($invoice->displayCurrency() !== config('currency.paypal_settlement', 'USD'))
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            Amounts are shown in <strong>{{ $invoice->displayCurrency() }}</strong>.
                            PayPal settles in <strong>{{ config('currency.paypal_settlement', 'USD') }}</strong> at the locked exchange rate.
                        </p>
                    @endif
                </div>

                <x-payment-method-options
                    :availableGateways="$availableGateways ?? []"
                    :defaultMethod="$defaultGateway"
                    method-model="selectedMethod"
                    phone-model="mpesaPhoneNumber"
                    :defaultPhone="$customerPhone"
                    recommended="mpesa"
                />

                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
                    <x-checkout.terms-agreement variant="payment" model="agreeTerms" :required="false" />
                </div>

                <div class="flex flex-wrap gap-3 pt-1">
                    <form
                        id="paymentForm"
                        method="POST"
                        action="{{ route('customer.payment.initiate', $invoice) }}"
                        x-show="selectedMethod !== 'manual' && selectedMethod !== 'bank_transfer'"
                        class="flex-1 min-w-[12rem]"
                    >
                        @csrf
                        <input type="hidden" name="payment_method" :value="selectedMethod">
                        <input type="hidden" name="phone" :value="mpesaPhoneNumber">
                        <button
                            type="submit"
                            :disabled="!canPay()"
                            :class="canPay() ? 'bg-blue-600 hover:bg-blue-700' : 'bg-slate-400 cursor-not-allowed'"
                            class="w-full px-6 py-3 text-white rounded-lg font-semibold transition"
                            x-text="ctaLabel()"
                        ></button>
                    </form>

                    <a
                        x-show="selectedMethod === 'bank_transfer'"
                        x-cloak
                        href="{{ route('customer.payment.bank-transfer-form', $invoice) }}"
                        class="flex-1 min-w-[12rem] px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition text-center"
                        :class="!agreeTerms ? 'pointer-events-none opacity-50' : ''"
                    >
                        Continue to bank transfer
                    </a>

                    <button
                        type="button"
                        x-show="selectedMethod === 'manual'"
                        x-cloak
                        @click="if (agreeTerms) showManualModal = true"
                        :disabled="!agreeTerms"
                        :class="agreeTerms ? 'bg-blue-600 hover:bg-blue-700' : 'bg-slate-400 cursor-not-allowed'"
                        class="flex-1 min-w-[12rem] px-6 py-3 text-white rounded-lg font-semibold transition"
                    >
                        Enter payment details
                    </button>

                    <a
                        href="{{ route('customer.invoices.show', $invoice) }}"
                        class="px-6 py-3 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                    >
                        Cancel
                    </a>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Secure checkout · SSL encrypted · Amount due is locked for this invoice
                </p>
            </div>
        </div>

        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4 space-y-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Order summary</h3>

                @php
                    $upgradePricing = null;
                    $upgradeItem = null;
                    foreach ($invoice->items as $item) {
                        $options = is_array($item->custom_options) ? $item->custom_options : [];
                        if (! empty($options['pricing_summary'])) {
                            $upgradePricing = $options['pricing_summary'];
                            $upgradeItem = $item;
                            break;
                        }
                        if (! empty($options['hosting_upgrade']) || ! empty($options['hosting_plan_change'])) {
                            $upgradeItem = $item;
                        }
                    }
                @endphp

                @if ($upgradePricing && ($upgradePricing['is_prorated'] ?? false))
                    <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/60 dark:bg-blue-950/20 p-3 text-sm text-slate-700 dark:text-slate-300">
                        Prorated upgrade for {{ $upgradePricing['days_remaining'] ?? 0 }}
                        {{ \Illuminate\Support\Str::plural('day', (int) ($upgradePricing['days_remaining'] ?? 0)) }} remaining
                        — not the full plan price.
                    </div>
                @elseif ($upgradeItem)
                    <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/60 dark:bg-blue-950/20 p-3 text-sm text-slate-700 dark:text-slate-300">
                        This invoice is a prorated hosting upgrade charge for the rest of your billing period.
                    </div>
                @endif

                <div class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-600 dark:text-slate-400">Invoice</span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-600 dark:text-slate-400">Due</span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ $invoice->due_date?->format('M d, Y') }}</span>
                    </div>
                </div>

                @if ($invoice->items->isNotEmpty())
                    <div class="border-t border-slate-200 dark:border-slate-700 pt-4 space-y-2">
                        @foreach ($invoice->items as $item)
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="text-slate-600 dark:text-slate-400">{{ $item->description }}</span>
                                <span class="font-medium text-slate-900 dark:text-white shrink-0">{{ $invoice->formatMoney($item->amount) }}</span>
                            </div>
                        @endforeach
                        @if ($invoice->tax > 0)
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="text-slate-600 dark:text-slate-400">Tax</span>
                                <span class="font-medium text-slate-900 dark:text-white shrink-0">{{ $invoice->formatMoney($invoice->tax) }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                @if (($appliedCredits ?? 0) > 0)
                    <div class="flex justify-between text-sm">
                        <span class="text-emerald-700 dark:text-emerald-300">Credits applied</span>
                        <span class="font-semibold text-emerald-600">− {{ $invoice->formatMoney($appliedCredits) }}</span>
                    </div>
                @endif

                <div class="border-t border-slate-200 dark:border-slate-700 pt-4 flex justify-between items-baseline gap-3">
                    <span class="font-semibold text-slate-900 dark:text-white">Amount due</span>
                    <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $invoice->formatMoney($amountDue) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Manual payment modal --}}
    <div x-show="showManualModal" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showManualModal = false" x-transition>
        <div class="bg-white dark:bg-slate-900 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto border border-slate-200 dark:border-slate-800">
            <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex justify-between items-center">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Submit manual payment</h2>
                <button type="button" @click="showManualModal = false" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300" aria-label="Close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 space-y-6">
                @php
                    $bankName = $bankDetails['bank_name'] ?? \App\Models\Setting::getValue('bank_name', '');
                    $bankAccountName = $bankDetails['bank_account_name'] ?? \App\Models\Setting::getValue('bank_account_name', '');
                    $bankAccountNumber = $bankDetails['bank_account_number'] ?? \App\Models\Setting::getValue('bank_account_number', '');
                    $bankBranch = $bankDetails['bank_branch'] ?? \App\Models\Setting::getValue('bank_branch', '');
                    $bankSwiftCode = $bankDetails['bank_swift_code'] ?? \App\Models\Setting::getValue('bank_swift_code', '');
                @endphp

                @if ($bankName || $bankAccountName || $bankAccountNumber)
                    <div class="border border-emerald-200 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950/20 rounded-xl p-5 space-y-3">
                        <h3 class="text-lg font-bold text-emerald-900 dark:text-emerald-300">Pay to this account</h3>
                        <div class="space-y-3 bg-white dark:bg-slate-800 rounded-lg p-4">
                            @if ($bankName)
                                <div>
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Bank name</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $bankName }}</p>
                                </div>
                            @endif
                            @if ($bankAccountName)
                                <div>
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Account name</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $bankAccountName }}</p>
                                </div>
                            @endif
                            @if ($bankAccountNumber)
                                <div class="flex items-center gap-3">
                                    <div class="flex-1">
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Account number</p>
                                        <p class="text-lg font-mono font-bold text-slate-900 dark:text-white">{{ $bankAccountNumber }}</p>
                                    </div>
                                    <button type="button" @click="navigator.clipboard.writeText(@js($bankAccountNumber))" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded font-medium transition">
                                        Copy
                                    </button>
                                </div>
                            @endif
                            @if ($bankBranch)
                                <div>
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Branch</p>
                                    <p class="text-sm text-slate-700 dark:text-slate-300">{{ $bankBranch }}</p>
                                </div>
                            @endif
                            @if ($bankSwiftCode)
                                <div>
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">SWIFT/BIC</p>
                                    <p class="text-sm font-mono text-slate-700 dark:text-slate-300">{{ $bankSwiftCode }}</p>
                                </div>
                            @endif
                        </div>
                        <p class="text-sm text-emerald-900 dark:text-emerald-300">
                            Amount to transfer: <strong>{{ $invoice->formatMoney($amountDue) }}</strong>
                        </p>
                    </div>
                @else
                    <div class="p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                        <p class="text-sm text-amber-900 dark:text-amber-300">Bank account details are not configured. Please contact support.</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('customer.payment.manual-submit', $invoice) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="manual_payment_reference" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">Transaction reference / slip number</label>
                        <input type="text" id="manual_payment_reference" name="payment_reference" placeholder="e.g., Bank slip or mobile money reference" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-slate-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label for="manual_bank_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">Bank / payment method</label>
                        <input type="text" id="manual_bank_name" name="bank_name" placeholder="e.g., KCB, Equity, M-Pesa" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-slate-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label for="manual_account_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">Your name on account</label>
                        <input type="text" id="manual_account_name" name="account_name" value="{{ auth()->user()->name }}" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-slate-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label for="manual_notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">Additional notes (optional)</label>
                        <textarea id="manual_notes" name="notes" rows="3" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-slate-900 dark:text-white text-sm resize-none"></textarea>
                    </div>
                    <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                        <button type="button" @click="showManualModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">Submit payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection
