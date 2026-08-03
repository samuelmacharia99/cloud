@extends('layouts.customer')

@section('title', 'Checkout')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.cart.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cart</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Checkout</p>
</div>
@endsection

@section('content')
@php
    $defaultGateway = array_key_first($availableGateways ?? []) ?: 'mpesa';
    $customerPhone = old('phone', auth()->user()->phone ?? '');
@endphp

<div
    class="max-w-5xl mx-auto space-y-6"
    x-data="{
        paymentMethod: @js($defaultGateway),
        mpesaPhone: @js($customerPhone),
        agreeTerms: false,
        ctaLabel() {
            return {
                mpesa: 'Send M-Pesa prompt',
                stripe: 'Continue to Stripe',
                paypal: 'Continue to PayPal',
                manual: 'Continue to payment',
                bank_transfer: 'Continue to bank transfer',
            }[this.paymentMethod] || 'Proceed to payment';
        },
        canPay() {
            if (! this.agreeTerms || ! this.paymentMethod) {
                return false;
            }
            if (this.paymentMethod === 'mpesa' && ! String(this.mpesaPhone || '').trim()) {
                return false;
            }
            return true;
        },
        submitPayment() {
            if (! this.canPay()) {
                return;
            }
            this.$refs.payForm.submit();
        }
    }"
>
    <div class="space-y-3">
        <x-checkout.steps current="pay" class="max-w-xl" />
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Checkout</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Review your order and choose a payment method</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order details</h2>
                    <div class="space-y-3">
                        @foreach ($invoice->items as $item)
                            <div class="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800 rounded-lg gap-4">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                        Qty: {{ $item->quantity }} × {{ $currencyCode }} {{ number_format($item->unit_price, 2) }}
                                    </p>
                                </div>
                                <p class="font-semibold text-slate-900 dark:text-white shrink-0">
                                    {{ $currencyCode }} {{ number_format($item->amount, 2) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="border-t border-slate-200 dark:border-slate-700 pt-6 space-y-4">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Payment method</h2>
                    <x-payment-method-options
                        :availableGateways="$availableGateways ?? []"
                        :defaultMethod="$defaultGateway"
                        :defaultPhone="$customerPhone"
                        recommended="mpesa"
                    />
                </div>

                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
                    <x-checkout.terms-agreement variant="payment" model="agreeTerms" :required="false" />
                </div>
            </div>
        </div>

        <div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-6 space-y-4">
                <h3 class="font-bold text-slate-900 dark:text-white">Order summary</h3>

                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="text-slate-900 dark:text-white">{{ $currencyCode }} {{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    @if ($invoice->tax > 0)
                        <div class="flex justify-between">
                            <span class="text-slate-600 dark:text-slate-400">Tax</span>
                            <span class="text-slate-900 dark:text-white">{{ $currencyCode }} {{ number_format($invoice->tax, 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-200 dark:border-slate-700 pt-4 flex justify-between items-baseline gap-3">
                    <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ $currencyCode }} {{ number_format($invoice->total, 2) }}</span>
                </div>

                <form
                    x-ref="payForm"
                    method="POST"
                    action="{{ route('customer.payment.initiate', $invoice) }}"
                    class="space-y-3"
                >
                    @csrf
                    <input type="hidden" name="payment_method" :value="paymentMethod">
                    <input type="hidden" name="phone" :value="mpesaPhone">
                    <button
                        type="button"
                        @click="submitPayment()"
                        :disabled="!canPay()"
                        :class="canPay() ? 'bg-blue-600 hover:bg-blue-700' : 'bg-slate-400 cursor-not-allowed'"
                        class="w-full px-6 py-3 text-white font-semibold rounded-lg transition"
                        x-text="ctaLabel()"
                    ></button>
                </form>

                <a href="{{ route('customer.invoices.show', $invoice) }}" class="block w-full px-6 py-2 text-center border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Cancel
                </a>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Secure checkout · SSL encrypted · Amount due is locked for this invoice
                </p>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection
