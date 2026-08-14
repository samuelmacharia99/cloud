@extends('layouts.reseller')

@section('title', 'Select Payment Method')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.invoices.show', $invoice) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Invoice</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Payment Method</p>
</div>
@endsection

@section('content')
@php
    $canPayWithWallet = (float) $wallet->balance > 0;
    $walletCoversInvoice = $canPayWithWallet && (float) $wallet->balance >= (float) $amountDue;
    $hasGateways = count($gateways) > 0;
    $defaultMethod = old('method', $walletCoversInvoice ? 'wallet' : ($hasGateways ? array_key_first($gateways) : ''));
@endphp
<div class="space-y-6 max-w-2xl" x-data="{
    method: @js($defaultMethod),
    applyWallet: {{ old('apply_wallet') || $walletCoversInvoice ? 'true' : 'false' }},
    walletBalance: {{ (float) $wallet->balance }},
    amountDue: {{ (float) $amountDue }},
    get walletApplied() {
        if (this.method === 'wallet' || this.applyWallet) {
            return Math.min(this.walletBalance, this.amountDue);
        }
        return 0;
    },
    get remaining() {
        return Math.max(0, this.amountDue - this.walletApplied);
    }
}">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Select Payment Method</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice #{{ $invoice->invoice_number }} — Amount due: KSH {{ number_format($amountDue, 2) }}</p>
        @if((float) $invoice->wallet_amount_applied > 0)
            <p class="text-sm text-emerald-600 dark:text-emerald-400 mt-1">Wallet applied: KSH {{ number_format($invoice->wallet_amount_applied, 2) }}</p>
        @endif
    </div>

    <form method="POST" action="{{ route('reseller.payment.initiate', $invoice) }}" class="space-y-6">
        @csrf

        @if($canPayWithWallet)
        <div class="space-y-3">
            <label class="relative flex items-start p-4 border-2 rounded-lg cursor-pointer transition"
                   :class="method === 'wallet' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950' : 'border-slate-200 dark:border-slate-700 hover:border-emerald-300'">
                <input type="radio" name="method" value="wallet" x-model="method"
                       @change="applyWallet = true"
                       class="w-5 h-5 mt-1 rounded-full border-slate-300 text-emerald-600 focus:ring-0 focus:border-emerald-500 transition"
                       @if(! $hasGateways) required @endif>
                <span class="ml-4 flex-1">
                    <span class="font-semibold text-slate-900 dark:text-white">Pay with wallet balance</span>
                    <span class="block text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Available: KSH {{ number_format($wallet->balance, 2) }}.
                        @if($walletCoversInvoice)
                            Covers this invoice in full.
                        @else
                            Covers KSH {{ number_format(min($wallet->balance, $amountDue), 2) }}; choose another method below for the remainder if needed.
                        @endif
                    </span>
                </span>
            </label>

            <input type="hidden" name="apply_wallet" value="1" x-bind:disabled="method !== 'wallet'">

            <label class="flex items-start gap-3 cursor-pointer rounded-lg border border-purple-200 dark:border-purple-800 bg-purple-50 dark:bg-purple-950/30 p-4"
                   x-show="method !== 'wallet'" x-cloak>
                <input type="checkbox" name="apply_wallet" value="1" x-model="applyWallet"
                       class="mt-1 rounded border-slate-300 text-purple-600">
                <span>
                    <span class="font-medium text-slate-900 dark:text-white">Apply wallet toward this payment</span>
                    <span class="block text-sm text-slate-600 dark:text-slate-400 mt-1">
                        Use KSH <span x-text="Math.min(walletBalance, amountDue).toFixed(2)"></span> from wallet, then pay KSH <span x-text="remaining.toFixed(2)"></span> with the selected method.
                    </span>
                </span>
            </label>
        </div>
        @endif

        @if($hasGateways)
        <div class="space-y-4">
            @foreach($gateways as $key => $gateway)
                <label class="relative flex items-start p-4 border-2 rounded-lg cursor-pointer transition"
                       :class="method === '{{ $key }}' ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-700 hover:border-purple-300'">
                    <input type="radio" name="method" value="{{ $key }}" x-model="method"
                           class="w-5 h-5 mt-1 rounded-full border-slate-300 text-purple-600 focus:ring-0 focus:border-purple-500 transition"
                           @if(! $canPayWithWallet) required @endif>
                    <div class="ml-4 flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $gateway['label'] }}</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $gateway['description'] }}</p>
                    </div>
                </label>

                @if($key === 'mpesa')
                <div class="ml-8 p-4 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg"
                     x-show="method === 'mpesa'" x-cloak>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                    <input type="tel" name="phone" placeholder="254712345678" value="{{ old('phone') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Format: 254XXXXXXXXX</p>
                </div>
                @endif
            @endforeach
        </div>
        @elseif(! $canPayWithWallet)
            <div class="bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                <p class="text-sm text-amber-900 dark:text-amber-100">No payment methods are currently available. Please contact support.</p>
            </div>
        @endif

        @if($canPayWithWallet || $hasGateways)
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4 text-sm text-slate-700 dark:text-slate-300">
                <div class="flex justify-between gap-4">
                    <span>Wallet applied</span>
                    <span class="font-semibold">KSH <span x-text="walletApplied.toFixed(2)"></span></span>
                </div>
                <div class="flex justify-between gap-4 mt-2">
                    <span>Remaining to charge</span>
                    <span class="font-semibold">KSH <span x-text="remaining.toFixed(2)"></span></span>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <a href="{{ route('reseller.invoices.show', $invoice) }}" class="flex-1 px-4 py-3 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                    Back
                </a>
                <button type="submit"
                        class="flex-1 px-4 py-3 bg-purple-600 hover:bg-purple-700 disabled:bg-slate-400 text-white font-medium rounded-lg transition"
                        :disabled="method === 'wallet' && remaining > 0">
                    Continue to Payment
                </button>
            </div>
        @endif
    </form>
</div>
@endsection
