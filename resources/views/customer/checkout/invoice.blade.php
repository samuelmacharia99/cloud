@extends('layouts.customer')

@section('title', 'Checkout')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('customer.cart.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cart</a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Checkout</p>
</div>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Checkout</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Review and pay for your order</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Order Summary -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                <!-- Invoice Items -->
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Details</h2>
                    <div class="space-y-3">
                        @foreach($invoice->items as $item)
                            <div class="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Qty: {{ $item->quantity }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">
                                        {{ $currencyCode }} {{ number_format($item->total, 2) }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <hr class="border-slate-200 dark:border-slate-700">

                <!-- Payment Method Selection -->
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Select Payment Method</h2>

                    <div class="space-y-3">
                        <!-- M-Pesa -->
                        <label class="flex items-center p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg hover:border-blue-500 dark:hover:border-blue-400 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="mpesa" class="w-4 h-4 text-blue-600" checked>
                            <div class="ml-4 flex-1">
                                <p class="font-semibold text-slate-900 dark:text-white">M-Pesa</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Pay via M-Pesa</p>
                            </div>
                        </label>

                        <!-- Stripe -->
                        <label class="flex items-center p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg hover:border-blue-500 dark:hover:border-blue-400 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="stripe" class="w-4 h-4 text-blue-600">
                            <div class="ml-4 flex-1">
                                <p class="font-semibold text-slate-900 dark:text-white">Card</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Pay with credit/debit card</p>
                            </div>
                        </label>

                        <!-- PayPal -->
                        <label class="flex items-center p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg hover:border-blue-500 dark:hover:border-blue-400 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="paypal" class="w-4 h-4 text-blue-600">
                            <div class="ml-4 flex-1">
                                <p class="font-semibold text-slate-900 dark:text-white">PayPal</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Pay via PayPal</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Terms -->
                <div class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg">
                    <input type="checkbox" id="agree_terms" class="w-4 h-4 rounded mt-1">
                    <label for="agree_terms" class="text-sm text-slate-700 dark:text-slate-300">
                        I agree to the terms and conditions and understand that my domain transfer will proceed after payment.
                    </label>
                </div>
            </div>
        </div>

        <!-- Price Summary Sidebar -->
        <div>
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sticky top-6 space-y-4">
                <h3 class="font-bold text-slate-900 dark:text-white">Order Summary</h3>

                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="text-slate-900 dark:text-white">{{ $currencyCode }} {{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    @if($invoice->tax > 0)
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Tax</span>
                            <span class="text-slate-900 dark:text-white">{{ $currencyCode }} {{ number_format($invoice->tax, 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
                    <div class="flex justify-between">
                        <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                        <span class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ $currencyCode }} {{ number_format($invoice->total, 2) }}</span>
                    </div>
                </div>

                <button id="payBtn" onclick="handlePayment('{{ $invoice->id }}')" class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition">
                    Proceed to Payment
                </button>

                <a href="{{ route('customer.domains.index') }}" class="block w-full px-6 py-2 text-center bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function handlePayment(invoiceId) {
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
    const agreeTerms = document.getElementById('agree_terms').checked;

    if (!paymentMethod) {
        alert('Please select a payment method');
        return;
    }

    if (!agreeTerms) {
        alert('Please agree to the terms and conditions');
        return;
    }

    // Redirect to payment method page
    window.location.href = `{{ route('customer.payment.initiate', '') }}/${invoiceId}?method=${paymentMethod}`;
}
</script>
@endsection
