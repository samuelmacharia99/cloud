@extends('layouts.customer')

@section('title', 'Shopping Cart')

@php
    $defaultNs = $defaultNameservers ?? app(\App\Services\ResellerNameserverService::class)->defaultsForCustomer(auth()->user());
    $defaultNameserverLabel = $defaultNameserverLabel ?? app(\App\Services\ResellerBrandingResolver::class)->forCustomer(auth()->user())['company_name'];
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
                @if($showHostingAttachPrompt ?? false)
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-950/40 dark:to-indigo-950/30 border border-blue-200 dark:border-blue-800 rounded-xl p-5">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">Add a hosting plan?</h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Optional — attach <strong>application hosting</strong> for your site, or a shared plan if you need email / classic DirectAdmin hosting. Domain and hosting stay on <strong>one invoice</strong> when you check out together.</p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2 shrink-0">
                                <a href="{{ route('customer.cart.attach-hosting') }}" class="inline-flex items-center justify-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm transition">
                                    Yes, add hosting
                                </a>
                                <a href="{{ route('customer.checkout.show') }}" class="inline-flex items-center justify-center px-5 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                    No, domain only
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                @if($hasSharedHosting ?? false)
                    <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-sm text-blue-900 dark:text-blue-100">
                        Domain setup (register, transfer, or use existing) is configured on the checkout page after you proceed.
                    </div>
                @endif

                <div class="ui-card overflow-hidden">
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
                                            <div
                                                x-data="domainDnsConfig(
                                                    '{{ $item['key'] }}',
                                                    {{ Js::from($item['nameservers'] ?? []) }},
                                                    {{ Js::from($defaultNs) }},
                                                    {{ Js::from($cloudflareDnsAvailable ?? false) }},
                                                    {{ Js::from($item['cloudflare_dns'] ?? false) }},
                                                    {{ Js::from($cloudflareNameservers ?? []) }}
                                                )"
                                                class="border border-slate-200 dark:border-slate-700 rounded-lg p-4 bg-white dark:bg-slate-900 mt-1"
                                            >
                                                <div class="flex items-center gap-2 mb-3">
                                                    <svg class="w-4 h-4 text-slate-500 dark:text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                                    </svg>
                                                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300">DNS / Name Servers</h4>
                                                    <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">Choose how this domain’s DNS is managed</span>
                                                </div>

                                                <div class="space-y-2">
                                                    <template x-if="cloudflareAvailable">
                                                        <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                                                            :class="mode === 'cloudflare' ? 'bg-indigo-50/70 dark:bg-indigo-950/30 ring-1 ring-indigo-200 dark:ring-indigo-800' : ''">
                                                            <input type="radio" name="dns_mode_{{ $item['key'] }}" class="mt-0.5 text-indigo-600 focus:ring-indigo-500"
                                                                :checked="mode === 'cloudflare'" @change="setMode('cloudflare')">
                                                            <div>
                                                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                                    Managed DNS (Cloudflare)
                                                                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">Recommended for apps</span>
                                                                </p>
                                                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Manage A, CNAME, MX, and TXT records in your account.</p>
                                                                <p class="text-xs font-mono text-indigo-700 dark:text-indigo-300 mt-1">
                                                                    <template x-if="cloudflareNs.ns1"><span x-text="cloudflareNs.ns1"></span></template>
                                                                    <template x-if="cloudflareNs.ns2"><span class="ml-2" x-text="cloudflareNs.ns2"></span></template>
                                                                </p>
                                                            </div>
                                                        </label>
                                                    </template>

                                                    <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                                                        :class="mode === 'platform' ? 'bg-emerald-50/70 dark:bg-emerald-950/20 ring-1 ring-emerald-200 dark:ring-emerald-800' : ''">
                                                        <input type="radio" name="dns_mode_{{ $item['key'] }}" class="mt-0.5 text-blue-600 focus:ring-blue-500"
                                                            :checked="mode === 'platform'" @change="setMode('platform')">
                                                        <div>
                                                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                                Use {{ $defaultNameserverLabel }} Nameservers
                                                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Platform default</span>
                                                            </p>
                                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">
                                                                <span x-text="defaults.ns1"></span><template x-if="defaults.ns2"><span class="ml-2" x-text="defaults.ns2"></span></template>
                                                            </p>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-3 cursor-pointer p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                                                        :class="mode === 'custom' ? 'bg-slate-50 dark:bg-slate-800/80 ring-1 ring-slate-200 dark:ring-slate-600' : ''">
                                                        <input type="radio" name="dns_mode_{{ $item['key'] }}" class="mt-0.5 text-blue-600 focus:ring-blue-500"
                                                            :checked="mode === 'custom'" @change="setMode('custom')">
                                                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Use Custom Nameservers</p>
                                                    </label>
                                                </div>

                                                <div x-show="mode === 'custom'" x-transition class="mt-4">
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

                                                <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100 dark:border-slate-700">
                                                    <div>
                                                        <p x-show="error" class="text-xs text-red-600 dark:text-red-400" x-text="error"></p>
                                                        <p x-show="message && !error" class="text-xs text-emerald-600 dark:text-emerald-400" x-text="message"></p>
                                                    </div>
                                                    <button
                                                        x-show="mode === 'custom'"
                                                        @click="saveCustom()"
                                                        :disabled="saving || customNs.length === 0"
                                                        class="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg font-medium transition"
                                                    >
                                                        <span x-show="!saving">Save Custom Nameservers</span>
                                                        <span x-show="saving">Saving...</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <form method="POST" action="{{ route('customer.cart.auto-renew', $item['key']) }}" class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700">
                                                @csrf
                                                @method('PUT')
                                                <label class="flex items-start gap-3 cursor-pointer">
                                                    <input type="hidden" name="auto_renew" value="0">
                                                    <input type="checkbox" name="auto_renew" value="1" class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500" @checked($item['auto_renew'] ?? false) onchange="this.form.submit()">
                                                    <span>
                                                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Auto-renew when this domain expires</span>
                                                        <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">Turns on at checkout only if your account credits already cover this renewal plus any other auto-renew domains. Credits are not reserved.</span>
                                                    </span>
                                                </label>
                                            </form>
                                        </td>
                                    </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="ui-card p-12 text-center">
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
                <div class="ui-card p-6 sticky top-4">
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
function domainDnsConfig(cartKey, stored, defaults, cloudflareAvailable, cloudflareEnabled, cloudflareNs) {
    const usingDefault = stored.use_default !== false;
    let initialMode = 'platform';
    if (cloudflareAvailable && cloudflareEnabled) {
        initialMode = 'cloudflare';
    } else if (!usingDefault) {
        initialMode = 'custom';
    }

    return {
        cartKey,
        defaults: defaults || {},
        cloudflareAvailable: !!cloudflareAvailable,
        cloudflareNs: cloudflareNs || {},
        mode: initialMode,
        nsInput: '',
        nsInputError: null,
        customNs: [],
        saving: false,
        message: '',
        error: null,

        init() {
            if (initialMode === 'custom') {
                [stored.ns1, stored.ns2, stored.ns3, stored.ns4]
                    .filter(Boolean)
                    .forEach(ns => this.customNs.push(ns));
            }
        },

        csrf() {
            return document.head.querySelector('meta[name="csrf-token"]').content;
        },

        async parseJson(res) {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Could not save DNS settings. Please refresh and try again.');
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

        async setMode(mode) {
            if (this.mode === mode && mode !== 'custom') {
                return;
            }

            this.error = null;
            this.message = '';
            this.mode = mode;

            if (mode === 'custom') {
                return;
            }

            this.saving = true;
            try {
                if (mode === 'cloudflare') {
                    const res = await fetch(`/my/cart/${this.cartKey}/cloudflare-dns`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf(),
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ enabled: true }),
                    });
                    const data = await this.parseJson(res);
                    if (!res.ok || !data.success) throw new Error(data.message || 'Failed to enable managed DNS');
                    this.message = data.message || 'Managed DNS enabled.';
                } else {
                    const res = await fetch(`/my/cart/${this.cartKey}/nameservers`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf(),
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            use_default: true,
                            ns1: this.defaults.ns1,
                            ns2: this.defaults.ns2 || null,
                            ns3: this.defaults.ns3 || null,
                            ns4: this.defaults.ns4 || null,
                        }),
                    });
                    const data = await this.parseJson(res);
                    if (!res.ok || !data.success) throw new Error(data.message || 'Failed to save nameservers');
                    this.message = data.message || 'Platform nameservers selected.';
                }
            } catch (err) {
                this.error = err.message;
            } finally {
                this.saving = false;
            }
        },

        async saveCustom() {
            this.error = null;
            this.message = '';

            if (this.customNs.length === 0) {
                this.error = 'Please add at least one nameserver';
                return;
            }

            this.saving = true;
            try {
                const res = await fetch(`/my/cart/${this.cartKey}/nameservers`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        use_default: false,
                        ns1: this.customNs[0] || null,
                        ns2: this.customNs[1] || null,
                        ns3: this.customNs[2] || null,
                        ns4: this.customNs[3] || null,
                    }),
                });
                const data = await this.parseJson(res);
                if (!res.ok || !data.success) throw new Error(data.message || 'Failed to save nameservers');
                this.mode = 'custom';
                this.message = data.message || 'Custom nameservers saved.';
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
