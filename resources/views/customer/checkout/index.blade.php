@extends('layouts.customer')

@section('title', 'Checkout')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Checkout</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and place your order</p>
    </div>

    <form action="{{ route('customer.checkout.process') }}" method="POST"
        x-data="checkoutPage({{ Js::from([
            'baseSubtotal' => $cartSubtotal ?? $subtotal,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'taxInclusive' => $taxInclusive ?? false,
        ]) }})"
        @checkout-domain-added.window="addDomainAddon($event.detail)"
        @checkout-domain-removed.window="onDomainRemoved($event.detail.cartKey)">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Items</h2>
                    <div class="space-y-3">
                        @foreach($cartItems as $item)
                            <div class="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item['name'] }}</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $item['description'] ?? '' }}</p>
                                </div>
                                <p class="font-semibold text-slate-900 dark:text-white">Ksh {{ number_format($item['amount'], 0) }}</p>
                            </div>
                        @endforeach
                        <template x-for="addon in domainAddonList()" :key="addon.cartKey">
                            <div class="flex justify-between items-center p-4 bg-emerald-50 dark:bg-emerald-950/30 rounded-lg border border-emerald-200 dark:border-emerald-800">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white" x-text="addon.label"></p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400" x-text="addon.description"></p>
                                    <button type="button" @click="removeDomainAddon(addon.cartKey)"
                                        class="mt-2 text-xs font-medium text-red-600 dark:text-red-400 hover:underline">
                                        Remove from cart
                                    </button>
                                </div>
                                <p class="font-semibold text-slate-900 dark:text-white" x-text="formatMoney(addon.amount)"></p>
                            </div>
                        </template>
                    </div>
                </div>

                @php
                    $containerProducts = array_filter($cartItems, fn($item) => ($item['type'] ?? null) === 'container_hosting');
                @endphp
                @if (!empty($containerProducts))
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Container Configuration</h2>
                        <div class="space-y-6">
                            @foreach($containerProducts as $key => $product)
                                @php $template = $product['container_template'] ?? null; @endphp
                                @if ($template)
                                    <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0">
                                        <h3 class="font-semibold text-slate-900 dark:text-white mb-4">{{ $product['name'] }}</h3>
                                        @include('customer.checkout.partials.container-fields', ['product' => $product, 'key' => $key, 'template' => $template])
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (!empty($sharedHostingItems))
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Shared Hosting Domain</h2>
                        @include('customer.checkout.partials.shared-hosting-domain')
                    </div>
                @endif

                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Your Information</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Full Name</label>
                            <p class="text-slate-900 dark:text-white font-medium">{{ $user->name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email Address</label>
                            <p class="text-slate-900 dark:text-white font-medium">{{ $user->email }}</p>
                        </div>
                        @if($user->phone)
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                                <p class="text-slate-900 dark:text-white font-medium">{{ $user->phone }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                    <h2 class="text-lg font-bold text-blue-900 dark:text-blue-100 mb-2">Payment Method</h2>
                    <p class="text-blue-800 dark:text-blue-200">
                        An invoice will be generated after you place your order. You can pay using M-Pesa, bank transfer, or other available payment methods.
                    </p>
                </div>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="agree_terms" value="1" required x-model="agree" class="mt-1 rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-600 dark:text-slate-400">
                        I agree to the Terms of Service and understand that an invoice will be generated after placing this order
                    </span>
                </label>

                <div class="flex gap-3">
                    <a href="{{ route('customer.cart.index') }}" class="flex-1 px-6 py-3 text-center text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Back to Cart
                    </a>
                    <button type="submit" :disabled="!agree" :class="!agree ? 'opacity-50 cursor-not-allowed' : ''"
                        class="flex-1 px-6 py-3 bg-green-600 hover:bg-green-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition">
                        Place Order
                    </button>
                </div>
            </div>

            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Summary</h3>
                    <div class="space-y-3 mb-4 pb-4 border-b border-slate-200 dark:border-slate-700">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                            <span class="font-medium text-slate-900 dark:text-white" x-text="formatMoney(displaySubtotal())"></span>
                        </div>
                        @if($taxEnabled)
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                                <span class="font-medium text-slate-900 dark:text-white" x-text="formatMoney(displayTax())"></span>
                            </div>
                        @endif
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                        <span class="text-2xl font-bold text-green-600 dark:text-green-400" x-text="formatMoney(displayTotal())"></span>
                    </div>
                    @if(!empty($sharedHostingItems))
                        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                            After checking availability, add your domain to the cart to include registration fees in the total above. Transfer fees are added when you place your order.
                        </p>
                    @endif
                    <div class="mt-6 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <p class="text-xs text-slate-600 dark:text-slate-400">
                            <strong>Note:</strong> Services will be activated automatically once your invoice is marked as paid.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function checkoutPage(config) {
    return {
        agree: false,
        baseSubtotal: config.baseSubtotal,
        taxEnabled: config.taxEnabled,
        taxRate: config.taxRate,
        taxInclusive: config.taxInclusive,
        domainAddons: {},

        addDomainAddon(detail) {
            this.domainAddons[detail.cartKey] = {
                cartKey: detail.cartKey,
                label: detail.label,
                description: detail.description,
                amount: detail.amount,
            };
        },

        removeDomainAddon(cartKey) {
            delete this.domainAddons[cartKey];
            window.dispatchEvent(new CustomEvent('checkout-domain-removed', { detail: { cartKey } }));
        },

        onDomainRemoved(cartKey) {
            delete this.domainAddons[cartKey];
        },

        domainAddonList() {
            return Object.values(this.domainAddons);
        },

        domainAddonsTotal() {
            return this.domainAddonList().reduce((sum, addon) => sum + addon.amount, 0);
        },

        displayGross() {
            return this.baseSubtotal + this.domainAddonsTotal();
        },

        displaySubtotal() {
            const gross = this.displayGross();
            if (! this.taxEnabled || this.taxRate <= 0) {
                return gross;
            }
            if (this.taxInclusive) {
                return gross / (1 + this.taxRate / 100);
            }

            return gross;
        },

        displayTax() {
            const gross = this.displayGross();
            if (! this.taxEnabled || this.taxRate <= 0) {
                return 0;
            }
            if (this.taxInclusive) {
                return gross - this.displaySubtotal();
            }

            return gross * this.taxRate / 100;
        },

        displayTotal() {
            const gross = this.displayGross();
            if (! this.taxEnabled || this.taxRate <= 0) {
                return gross;
            }
            if (this.taxInclusive) {
                return gross;
            }

            return gross + this.displayTax();
        },

        formatMoney(amount) {
            return 'Ksh ' + Math.round(amount).toLocaleString();
        },
    };
}
</script>
@endpush
