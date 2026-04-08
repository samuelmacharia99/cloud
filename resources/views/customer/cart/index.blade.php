@extends('layouts.customer')

@section('title', 'Shopping Cart')

@php
    $domainExtensions = \App\Models\DomainExtension::where('enabled', true)->orderBy('extension')->get();
@endphp

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Shopping Cart</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and manage your selected services</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Items & Domain Attachment -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Cart Items -->
            @if($itemCount > 0)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Billing</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Price</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach($cartItems as $item)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                        <td class="px-6 py-4">
                                            <div>
                                                <p class="font-medium text-slate-900 dark:text-white">{{ $item['name'] }}</p>
                                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $item['description'] }}</p>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                            @if(isset($item['billing_cycle']))
                                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                    {{ ucfirst($item['billing_cycle']) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                    {{ $item['years'] ?? 1 }} Year(s)
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <p class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($item['amount'], 0) }}</p>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <form action="{{ route('customer.cart.remove', $item['key']) }}" method="POST" style="display: inline;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 text-sm font-medium">
                                                    Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Add Domain Section -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6" x-data="domainChecker()">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Add Domain (Optional)</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Register a domain to use with your service</p>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <!-- Domain Name -->
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
                                <input type="text" x-model="domain" placeholder="example" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 text-sm" required>
                                <p x-show="domainError" class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="domainError"></p>
                            </div>

                            <!-- Domain Extension -->
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                                <select x-model="extension" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm" required>
                                    <option value="">Select extension...</option>
                                    @foreach($domainExtensions as $ext)
                                        <option value="{{ $ext->extension }}">{{ $ext->extension }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Duration -->
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Duration</label>
                                <select x-model="years" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm" required>
                                    <option value="1">1 Year</option>
                                    <option value="2">2 Years</option>
                                    <option value="3">3 Years</option>
                                    <option value="5">5 Years</option>
                                    <option value="10">10 Years</option>
                                </select>
                            </div>

                            <!-- Check Availability Button -->
                            <div class="flex items-end">
                                <button type="button" @click="checkAvailability()" :disabled="!domain || !extension || checking" :class="!domain || !extension || checking ? 'opacity-50 cursor-not-allowed bg-slate-400' : 'bg-blue-600 hover:bg-blue-700'" class="w-full px-4 py-2 text-white rounded-lg font-medium transition text-sm">
                                    <span x-show="!checking">Check Availability</span>
                                    <span x-show="checking" class="inline-flex items-center justify-center gap-2">
                                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Checking...
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Availability Status -->
                        <div x-show="checked && domain && extension" class="p-4 rounded-lg" :class="available ? 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700'">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3 flex-1">
                                    <svg x-show="available" class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    <svg x-show="!available" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <p class="font-semibold" :class="available ? 'text-emerald-900 dark:text-emerald-100' : 'text-red-900 dark:text-red-100'" x-text="statusMessage"></p>
                                        <p x-show="available" class="text-sm text-emerald-700 dark:text-emerald-300 mt-1">
                                            <span x-text="`Ksh ${price.toLocaleString()} per year`"></span>
                                        </p>
                                    </div>
                                </div>

                                <!-- Add to Cart Button (Only if available) -->
                                <form x-show="available" action="{{ route('customer.cart.add') }}" method="POST" class="flex-shrink-0">
                                    @csrf
                                    <input type="hidden" name="type" value="domain">
                                    <input type="hidden" name="domain" :value="domain">
                                    <input type="hidden" name="extension" :value="extension">
                                    <input type="hidden" name="years" :value="years">
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition text-sm whitespace-nowrap">
                                        Add to Cart
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <p class="text-slate-500 dark:text-slate-400 text-lg mb-4">Your cart is empty</p>
                    <a href="{{ route('customer.deploy-service') }}" class="inline-flex items-center px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Continue Shopping
                    </a>
                </div>
            @endif
        </div>

        <!-- Summary -->
        @if($itemCount > 0)
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Summary</h3>

                    <div class="space-y-3 mb-4 pb-4 border-b border-slate-200 dark:border-slate-700">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                            <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($subtotal, 0) }}</span>
                        </div>

                        @if($taxEnabled)
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                                <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($tax, 0) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-between mb-6">
                        <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">Ksh {{ number_format($total, 0) }}</span>
                    </div>

                    <a href="{{ route('customer.checkout.show') }}" class="block w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-center transition mb-3">
                        Proceed to Checkout
                    </a>

                    <form action="{{ route('customer.cart.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                            Clear Cart
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
function domainChecker() {
    return {
        domain: '',
        extension: '',
        years: '1',
        checking: false,
        checked: false,
        available: false,
        price: 0,
        domainError: '',
        statusMessage: '',

        async checkAvailability() {
            if (!this.domain || !this.extension) {
                return;
            }

            // Validate domain name
            if (!this.isValidDomain(this.domain)) {
                this.domainError = 'Domain name can only contain letters, numbers, and hyphens';
                this.checked = false;
                return;
            } else {
                this.domainError = '';
            }

            this.checking = true;
            this.checked = false;

            try {
                const response = await fetch('{{ route("customer.cart.check-domain") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        domain: this.domain,
                        extension: this.extension,
                    }),
                });

                const data = await response.json();

                if (data.success) {
                    this.available = data.available;
                    this.price = data.price;
                    this.statusMessage = data.message;
                    this.checked = true;
                } else {
                    this.available = false;
                    this.statusMessage = data.message || 'Error checking availability';
                    this.checked = true;
                }
            } catch (error) {
                this.available = false;
                this.statusMessage = 'Error checking domain availability';
                this.checked = true;
                console.error(error);
            } finally {
                this.checking = false;
            }
        },

        isValidDomain(domain) {
            const regex = /^[a-z0-9-]+$/i;
            return regex.test(domain) && !domain.startsWith('-') && !domain.endsWith('-');
        },
    };
}
</script>
@endsection
