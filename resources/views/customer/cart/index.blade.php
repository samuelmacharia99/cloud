@extends('layouts.customer')

@section('title', 'Shopping Cart')

@php
    $defaultNs = $defaultNameservers ?? app(\App\Services\NodeNameserverService::class)->platformDefaults();
@endphp

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Shopping Cart</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and manage your selected services</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Cart Items -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Cart Items -->
            @if($itemCount > 0)
                @if($hasSharedHosting ?? false)
                    <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-sm text-blue-900 dark:text-blue-100">
                        Domain setup (register, transfer, or use existing) is configured on the checkout page after you proceed.
                    </div>
                @endif

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
                                    @if($item['type'] === 'domain')
                                    <tr class="bg-slate-50/50 dark:bg-slate-800/50">
                                        <td colspan="4" class="px-6 pb-5 pt-0">
                                            <div x-data="nameserverConfig(
                                                '{{ $item['key'] }}',
                                                {{ Js::from($item['nameservers'] ?? []) }},
                                                {{ Js::from($defaultNs) }}
                                            )" class="border border-slate-200 dark:border-slate-700 rounded-lg p-4 bg-white dark:bg-slate-900 mt-1">
                                                <!-- header -->
                                                <div class="flex items-center gap-2 mb-3">
                                                    <svg class="w-4 h-4 text-slate-500 dark:text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                                    </svg>
                                                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Name Servers</h4>
                                                    <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">Sets DNS authority for this domain</span>
                                                </div>

                                                <!-- radio toggle -->
                                                <div class="space-y-2">
                                                    <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                                        <input type="radio" name="ns_mode_{{ $item['key'] }}" @change="useDefault = true" :checked="useDefault" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                                        <div>
                                                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                                Use Talksasa Cloud Nameservers
                                                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Recommended</span>
                                                            </p>
                                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">
                                                                <span x-text="defaults.ns1"></span><template x-if="defaults.ns2"><span class="ml-2" x-text="defaults.ns2"></span></template>
                                                            </p>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                                        <input type="radio" name="ns_mode_{{ $item['key'] }}" @change="useDefault = false" :checked="!useDefault" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Use Custom Nameservers</p>
                                                    </label>
                                                </div>

                                                <!-- custom ns fields -->
                                                <div x-show="!useDefault" x-transition class="mt-4">
                                                    <div class="flex gap-2">
                                                        <input
                                                            type="text"
                                                            x-model="nsInput"
                                                            @keydown.enter.prevent="addNs()"
                                                            placeholder="Type a nameserver (e.g. ns1.yourdomain.com)"
                                                            :disabled="customNs.length >= 4"
                                                            class="flex-1 px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50"
                                                        >
                                                        <button
                                                            type="button"
                                                            @click="addNs()"
                                                            :disabled="!nsInput.trim() || customNs.length >= 4"
                                                            class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg font-medium transition"
                                                        >
                                                            + Add
                                                        </button>
                                                    </div>
                                                    <p x-show="nsInputError" class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="nsInputError"></p>
                                                    <p class="text-xs mt-1" :class="customNs.length === 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400 dark:text-slate-500'">
                                                        <template x-if="customNs.length === 0"><span>At least one nameserver is required</span></template>
                                                        <template x-if="customNs.length > 0 && customNs.length < 4"><span x-text="`${customNs.length}/4 nameservers added`"></span></template>
                                                        <template x-if="customNs.length === 4"><span>Maximum 4 nameservers reached</span></template>
                                                    </p>
                                                    <div x-show="customNs.length > 0" class="flex flex-wrap gap-2 mt-3">
                                                        <template x-for="(ns, idx) in customNs" :key="idx">
                                                            <div class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-full text-xs font-mono text-slate-700 dark:text-slate-300">
                                                                <svg class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                                                </svg>
                                                                <span x-text="ns"></span>
                                                                <button type="button" @click="removeNs(idx)" class="ml-0.5 rounded-full hover:bg-slate-200 dark:hover:bg-slate-600 p-0.5 transition">
                                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>

                                                <!-- save bar -->
                                                <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100 dark:border-slate-700">
                                                    <div>
                                                        <p x-show="error" class="text-xs text-red-600 dark:text-red-400" x-text="error"></p>
                                                        <p x-show="saved && !error" class="text-xs text-emerald-600 dark:text-emerald-400">✓ Nameservers saved</p>
                                                    </div>
                                                    <button @click="save()" :disabled="saving || (!useDefault && customNs.length === 0)"
                                                        class="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg font-medium transition">
                                                        <span x-show="!saving">Save Nameservers</span>
                                                        <span x-show="saving">Saving...</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
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
function nameserverConfig(cartKey, stored, defaults) {
    const usingDefault = stored.use_default !== false;
    return {
        cartKey,
        defaults,
        useDefault: usingDefault,
        nsInput: '',
        nsInputError: null,
        customNs: [],
        saving: false,
        saved: false,
        error: null,

        init() {
            if (!usingDefault) {
                [stored.ns1, stored.ns2, stored.ns3, stored.ns4]
                    .filter(Boolean)
                    .forEach(ns => this.customNs.push(ns));
            }
        },

        addNs() {
            const val = this.nsInput.trim().toLowerCase();
            if (!val) return;
            if (this.customNs.length >= 4) { this.nsInputError = 'Maximum 4 nameservers'; return; }
            if (this.customNs.includes(val)) { this.nsInputError = 'Already added'; return; }
            if (!/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/.test(val)) {
                this.nsInputError = 'Invalid hostname format';
                return;
            }
            this.customNs.push(val);
            this.nsInput = '';
            this.nsInputError = null;
        },

        removeNs(idx) {
            this.customNs.splice(idx, 1);
        },

        async save() {
            this.error = null;
            this.saved = false;

            if (!this.useDefault && this.customNs.length === 0) {
                this.error = 'Please add at least one nameserver';
                return;
            }

            const payload = {
                use_default: this.useDefault,
                ns1: this.useDefault ? this.defaults.ns1 : (this.customNs[0] || null),
                ns2: this.useDefault ? (this.defaults.ns2 || null) : (this.customNs[1] || null),
                ns3: this.useDefault ? (this.defaults.ns3 || null) : (this.customNs[2] || null),
                ns4: this.useDefault ? (this.defaults.ns4 || null) : (this.customNs[3] || null),
            };

            this.saving = true;
            try {
                const res = await fetch(`/cart/${this.cartKey}/nameservers`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Failed to save nameservers');
                this.saved = true;
                setTimeout(() => { this.saved = false; }, 4000);
            } catch (err) {
                this.error = err.message;
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>
@endsection
