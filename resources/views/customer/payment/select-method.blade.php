@extends('layouts.customer')

@section('title', 'Select Payment Method')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Pay Invoice</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice #{{ $invoice->invoice_number }} - Ksh {{ number_format($invoice->total, 0) }}</p>
    </div>

    <!-- Invoice Summary -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice Details</h2>

        <div class="space-y-3">
            <div class="flex justify-between">
                <span class="text-slate-600 dark:text-slate-400">Invoice Number</span>
                <span class="font-semibold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600 dark:text-slate-400">Issue Date</span>
                <span class="font-semibold text-slate-900 dark:text-white">{{ $invoice->created_at->format('M d, Y') }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600 dark:text-slate-400">Due Date</span>
                <span class="font-semibold text-slate-900 dark:text-white">{{ $invoice->due_date->format('M d, Y') }}</span>
            </div>
            <hr class="my-4 border-slate-200 dark:border-slate-700">
            <div class="flex justify-between text-lg">
                <span class="font-semibold text-slate-900 dark:text-white">Amount Due</span>
                <span class="font-bold text-blue-600 dark:text-blue-400">Ksh {{ number_format($invoice->total, 0) }}</span>
            </div>
        </div>
    </div>

    <!-- Payment Methods -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Select Payment Method</h2>

        @if (session('success'))
            <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg">
                <p class="text-emerald-700 dark:text-emerald-300 text-sm">{{ session('success') }}</p>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
                <p class="text-red-700 dark:text-red-300 text-sm">{{ session('error') }}</p>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach ($availableGateways as $method => $gateway)
                <form action="{{ route('customer.payment.initiate', $invoice) }}" method="POST" class="contents">
                    @csrf

                    <button type="button" @click="selectPaymentMethod('{{ $method }}')" class="group overflow-hidden rounded-xl border-2 transition-all duration-300 p-6 text-center hover:shadow-lg" :class="selectedMethod === '{{ $method }}' ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800 shadow-lg' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-600'">
                        <!-- Icon -->
                        <div class="text-3xl mb-3 flex justify-center">
                            @if ($method === 'mpesa')
                                <svg class="w-8 h-8 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/>
                                </svg>
                            @elseif ($method === 'stripe')
                                <svg class="w-8 h-8 text-purple-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M13 17h8v2h-8v-2zm0-5h8v2h-8v-2zm0-5h8v2h-8V7zM3 17h8v2H3v-2zm0-5h8v2H3v-2zm0-5h8v2H3V7z"/>
                                </svg>
                            @else
                                <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/>
                                </svg>
                            @endif
                        </div>

                        <!-- Label -->
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">{{ $gateway['label'] }}</h3>

                        <!-- Description -->
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ $gateway['description'] }}</p>

                        <!-- Hidden form fields -->
                        <input type="hidden" name="payment_method" value="{{ $method }}">
                        @if ($method === 'mpesa')
                            <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-PESA Phone Number</label>
                                <input type="tel" name="phone" placeholder="0712345678" x-show="selectedMethod === '{{ $method }}'" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required>
                            </div>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>

        <!-- Submit Button -->
        <div class="mt-6 flex gap-3">
            <form id="paymentForm" method="POST" action="{{ route('customer.payment.initiate', $invoice) }}" x-data="{selectedMethod: null}">
                @csrf
                <input type="hidden" name="payment_method" x-bind:value="selectedMethod">
                <input type="hidden" name="phone" id="phoneInput" x-bind:value="selectedMethod === 'mpesa' ? document.querySelector('input[name=phone]')?.value : null">

                <button type="submit" :disabled="!selectedMethod" :class="selectedMethod ? 'bg-blue-600 hover:bg-blue-700' : 'opacity-50 cursor-not-allowed bg-slate-400'" class="flex-1 px-6 py-3 text-white rounded-lg font-semibold transition">
                    Continue to Payment
                </button>
            </form>

            <a href="{{ route('customer.invoices.show', $invoice) }}" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                Cancel
            </a>
        </div>
    </div>
</div>

<script>
function selectPaymentMethod(method) {
    document.getElementById('paymentForm').querySelector('input[name="payment_method"]').value = method;
    document.querySelector('[x-data]').__x.$data.selectedMethod = method;
}
</script>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection
