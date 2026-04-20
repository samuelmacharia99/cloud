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
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6" x-data="{ selectedMethod: null, showManualModal: false, mpesaPhoneNumber: '' }">
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

        <!-- Payment Methods Grid -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            @foreach ($availableGateways as $method => $gateway)
                <button type="button" @click="selectedMethod = '{{ $method }}'; @if($method === 'manual') showManualModal = true @endif" class="relative group overflow-hidden rounded-lg border-2 transition-all duration-300 p-4 text-center hover:shadow-lg" :class="selectedMethod === '{{ $method }}' ? (selectedMethod === 'mpesa' ? 'border-green-500 dark:border-green-400 bg-green-50 dark:bg-slate-800 shadow-lg' : (selectedMethod === 'stripe' ? 'border-purple-500 dark:border-purple-400 bg-purple-50 dark:bg-slate-800 shadow-lg' : 'border-blue-500 dark:border-blue-400 bg-blue-50 dark:bg-slate-800 shadow-lg')) : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-600'">

                    <!-- Glow effect for mpesa when selected -->
                    @if ($method === 'mpesa')
                        <div class="absolute inset-0 bg-gradient-to-br from-green-400/0 to-green-400/0" :class="selectedMethod === 'mpesa' ? 'from-green-400/10' : ''"></div>
                        <div class="absolute -top-1 -right-1 z-20" x-show="selectedMethod === 'mpesa'">
                            <span class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                            </span>
                        </span>
                        </div>
                    @endif

                    <!-- Badge -->
                    @if ($method === 'mpesa')
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl mb-2 mx-auto">
                            <span class="text-white font-black text-lg">M</span>
                        </div>
                    @elseif ($method === 'stripe')
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl mb-2 mx-auto">
                            <span class="text-white font-bold text-lg">S</span>
                        </div>
                    @elseif ($method === 'paypal')
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl mb-2 mx-auto">
                            <span class="text-white font-bold text-lg">P</span>
                        </div>
                    @else
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-slate-500 to-slate-600 rounded-xl mb-2 mx-auto">
                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/>
                            </svg>
                        </div>
                    @endif

                    <!-- Label -->
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $gateway['label'] }}</h3>

                    @if ($method === 'mpesa')
                        <p class="text-xs text-green-700 dark:text-green-300 font-medium mt-1">Recommended</p>
                    @endif
                </button>
            @endforeach
        </div>

        <!-- Input Section (appears when method selected) -->
        <div x-show="selectedMethod !== null && selectedMethod !== 'manual'" class="border-2 border-slate-200 dark:border-slate-700 rounded-lg p-6 bg-slate-50 dark:bg-slate-800/50" x-transition>

            <!-- M-Pesa Input -->
            <div x-show="selectedMethod === 'mpesa'" class="space-y-4" x-transition>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">M-PESA Payment</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">Enter your M-PESA phone number to receive the payment prompt</p>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                    <input type="tel" id="mpesaPhone" placeholder="0712345678 or 254712345678" class="w-full px-4 py-3 border-2 border-green-300 dark:border-green-700 rounded-lg text-base bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-green-500 focus:ring-2 focus:ring-green-200 dark:focus:ring-green-900/50 transition-all placeholder-slate-400 dark:placeholder-slate-500" required x-model="mpesaPhoneNumber">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Format: 0712345678 or 254712345678</p>
                </div>
            </div>

            <!-- Stripe Input -->
            <div x-show="selectedMethod === 'stripe'" class="space-y-4" x-transition>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Stripe Payment</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">You'll be redirected to Stripe's secure checkout</p>
            </div>

            <!-- PayPal Input -->
            <div x-show="selectedMethod === 'paypal'" class="space-y-4" x-transition>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">PayPal Payment</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">You'll be redirected to PayPal's secure checkout</p>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="mt-6 flex gap-3">
            <form id="paymentForm" method="POST" action="{{ route('customer.payment.initiate', $invoice) }}" x-show="selectedMethod !== 'manual'">
                @csrf
                <input type="hidden" name="payment_method" x-bind:value="selectedMethod">
                <!-- Pass the phone number from Alpine.js reactive variable -->
                <input type="hidden" name="phone" x-bind:value="mpesaPhoneNumber">

                <button type="submit" :disabled="!selectedMethod || (selectedMethod === 'mpesa' && !mpesaPhoneNumber)" :class="selectedMethod && !(selectedMethod === 'mpesa' && !mpesaPhoneNumber) ? 'bg-blue-600 hover:bg-blue-700' : 'opacity-50 cursor-not-allowed bg-slate-400'" class="flex-1 px-6 py-3 text-white rounded-lg font-semibold transition">
                    Continue to Payment
                </button>
            </form>

            <button type="button" x-show="selectedMethod === 'manual'" @click="showManualModal = true" :disabled="!selectedMethod" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                Enter Payment Details
            </button>

            <a href="{{ route('customer.invoices.show', $invoice) }}" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                Cancel
            </a>
        </div>

        <!-- Manual Payment Modal -->
        <div x-show="showManualModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showManualModal = false" x-transition>
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <!-- Modal Header -->
                <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">Submit Manual Payment</h2>
                    <button type="button" @click="showManualModal = false" class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Modal Content -->
                <div class="p-6 space-y-6">
                    @php
                        $bankName = \App\Models\Setting::getValue('bank_name', '');
                        $bankAccountName = \App\Models\Setting::getValue('bank_account_name', '');
                        $bankAccountNumber = \App\Models\Setting::getValue('bank_account_number', '');
                        $bankBranch = \App\Models\Setting::getValue('bank_branch', '');
                        $bankSwiftCode = \App\Models\Setting::getValue('bank_swift_code', '');
                    @endphp

                    <!-- Where to Pay Section -->
                    @if($bankName || $bankAccountName || $bankAccountNumber)
                        <div class="border-2 border-emerald-200 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950/20 rounded-xl p-5">
                            <h3 class="text-lg font-bold text-emerald-900 dark:text-emerald-300 mb-4 flex items-center gap-2">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                </svg>
                                Pay to This Account
                            </h3>

                            <div class="space-y-3 bg-white dark:bg-slate-800 rounded-lg p-4">
                                @if($bankName)
                                    <div>
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Bank Name</p>
                                        <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $bankName }}</p>
                                    </div>
                                @endif

                                @if($bankAccountName)
                                    <div>
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Account Name</p>
                                        <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $bankAccountName }}</p>
                                    </div>
                                @endif

                                @if($bankAccountNumber)
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1">
                                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Account Number</p>
                                            <p class="text-lg font-mono font-bold text-slate-900 dark:text-white">{{ $bankAccountNumber }}</p>
                                        </div>
                                        <button type="button" @click="navigator.clipboard.writeText('{{ $bankAccountNumber }}'); alert('Account number copied!')" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded font-medium transition">
                                            Copy
                                        </button>
                                    </div>
                                @endif

                                @if($bankBranch)
                                    <div>
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Branch</p>
                                        <p class="text-sm text-slate-700 dark:text-slate-300">{{ $bankBranch }}</p>
                                    </div>
                                @endif

                                @if($bankSwiftCode)
                                    <div>
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">SWIFT/BIC Code</p>
                                        <p class="text-sm font-mono text-slate-700 dark:text-slate-300">{{ $bankSwiftCode }}</p>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3 p-3 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg border border-emerald-200 dark:border-emerald-700">
                                <p class="text-sm text-emerald-900 dark:text-emerald-300">
                                    ✓ <strong>Amount to Transfer:</strong> <span class="font-bold">Ksh {{ number_format($invoice->total, 0) }}</span>
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <p class="text-sm text-amber-900 dark:text-amber-300">
                                ⚠️ Bank account details are not configured. Please contact support.
                            </p>
                        </div>
                    @endif

                    <!-- Form Section -->
                    <form method="POST" action="{{ route('customer.payment.manual-submit', $invoice) }}" class="space-y-4">
                        @csrf

                        <div class="p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <p class="text-sm text-blue-900 dark:text-blue-300">
                                <strong>Next:</strong> Fill in the details below to confirm your payment submission. An admin will verify and approve it.
                            </p>
                        </div>

                        <!-- Payment Reference -->
                        <div>
                            <label for="manual_payment_reference" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">
                                Transaction Reference / Slip Number
                            </label>
                            <input type="text"
                                   id="manual_payment_reference"
                                   name="payment_reference"
                                   placeholder="e.g., Bank slip or mobile money reference"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        </div>

                        <!-- Bank Name -->
                        <div>
                            <label for="manual_bank_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">
                                Bank / Payment Method
                            </label>
                            <input type="text"
                                   id="manual_bank_name"
                                   name="bank_name"
                                   placeholder="e.g., KCB, Equity, M-Pesa"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        </div>

                        <!-- Account Name -->
                        <div>
                            <label for="manual_account_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">
                                Your Name on Account
                            </label>
                            <input type="text"
                                   id="manual_account_name"
                                   name="account_name"
                                   placeholder="{{ auth()->user()->name }}"
                                   value="{{ auth()->user()->name }}"
                                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        </div>

                        <!-- Notes -->
                        <div>
                            <label for="manual_notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-1">
                                Additional Notes (optional)
                            </label>
                            <textarea id="manual_notes"
                                      name="notes"
                                      rows="3"
                                      placeholder="Any extra details to help verify the payment..."
                                      class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none"></textarea>
                        </div>

                        <!-- Buttons -->
                        <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                            <button type="button" @click="showManualModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                Cancel
                            </button>
                            <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                Submit Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection
