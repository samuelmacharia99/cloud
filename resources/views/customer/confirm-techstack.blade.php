@extends('layouts.customer')

@section('title', 'Confirm Techstack & Choose Package')

@section('content')
<div class="space-y-6" x-data="packageSelector()">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Choose Your Hosting Package</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Select a plan that matches your needs</p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="relative">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            @if($cartCount > 0)
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    <!-- Techstack Summary -->
    <div class="bg-gradient-to-r from-blue-50 to-purple-50 dark:from-slate-800 dark:to-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Your Selection</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Language</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $language->name }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Database</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $database->name }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Hosting Type</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $routing['hosting_type'] === 'directadmin' ? '🌐 Shared Hosting' : '🐳 Container Hosting' }}</p>
            </div>
            <div class="text-right">
                <a href="{{ route('customer.select-techstack') }}" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">Change →</a>
            </div>
        </div>
    </div>

    <!-- Available Packages Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($products as $product)
        <button
            type="button"
            @click="selectProduct({{ $product->id }}, '{{ $product->name }}', {{ $product->monthly_price }})"
            class="relative group overflow-hidden rounded-xl border-2 transition-all duration-300 p-6 text-left hover:shadow-lg"
            :class="selectedProductId === {{ $product->id }}
                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800 shadow-lg'
                : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-blue-400 dark:hover:border-blue-600'"
        >
            <!-- Selected Badge -->
            <template x-if="selectedProductId === {{ $product->id }}">
                <div class="absolute top-0 right-0 bg-blue-600 text-white px-3 py-1 text-xs font-semibold">SELECTED</div>
            </template>

            <!-- Plan Name -->
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">{{ $product->name }}</h3>

            <!-- Price -->
            <div class="mb-4">
                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">KES {{ number_format($product->monthly_price, 0) }}</p>
                <p class="text-sm text-slate-600 dark:text-slate-400">per month</p>
            </div>

            <!-- Description -->
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">{{ $product->description }}</p>

            <!-- Features -->
            @if($product->features && count($product->features) > 0)
            <ul class="space-y-2 mb-4">
                @foreach(array_slice($product->features, 0, 3) as $feature)
                <li class="text-sm text-slate-700 dark:text-slate-300 flex items-center gap-2">
                    <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ $feature }}
                </li>
                @endforeach
                @if(count($product->features) > 3)
                <li class="text-sm text-slate-600 dark:text-slate-400">+ {{ count($product->features) - 3 }} more features</li>
                @endif
            </ul>
            @endif

            <!-- Click Prompt -->
            <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                <p class="text-xs text-slate-500 dark:text-slate-400 group-hover:text-slate-700 dark:group-hover:text-slate-200 transition">Click to select this plan →</p>
            </div>
        </button>
        @endforeach
    </div>

    <!-- Add to Cart Section -->
    <template x-if="selectedProductId">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Finalize Your Order</h2>

        <form action="{{ route('customer.cart.add') }}" method="POST" class="space-y-4" x-data="{ cycle: 'monthly', version: '{{ $language->versions[0] ?? '' }}' }">
            @csrf
            <input type="hidden" name="type" value="product">
            <input type="hidden" name="product_id" :value="selectedProductId">
            <input type="hidden" name="billing_cycle" x-bind:value="cycle">
            @if($language->versions && count($language->versions) > 0)
                <input type="hidden" name="version" x-bind:value="version">
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Version Selector -->
                @if($language->versions && count($language->versions) > 0)
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">{{ $language->name }} Version</label>
                    <select x-model="version" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        @foreach($language->versions as $version)
                            <option value="{{ $version }}">v{{ $version }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <!-- Billing Cycle -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Billing Cycle</label>
                    <select x-model="cycle" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="semi-annual">Semi-Annual</option>
                        <option value="annual">Annual</option>
                    </select>
                </div>

                <!-- Summary -->
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                    <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Total Price</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white" x-text="'KES ' + Number(selectedProductPrice).toLocaleString()"></p>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" x-text="getCycleLabel()"></p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Add to Cart
                </button>
                <a href="{{ route('customer.select-techstack') }}" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    Change Techstack
                </a>
            </div>
        </form>
    </div>
    </template>

    <!-- No Selection Placeholder -->
    <template x-if="!selectedProductId">
    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 p-12 text-center">
        <p class="text-slate-600 dark:text-slate-400 text-lg">Select a package above to continue</p>
    </div>
    </template>
</div>

<script>
function packageSelector() {
    return {
        selectedProductId: null,
        selectedProductName: '',
        selectedProductPrice: 0,

        selectProduct(productId, productName, productPrice) {
            this.selectedProductId = productId;
            this.selectedProductName = productName;
            this.selectedProductPrice = productPrice;
        },

        getCycleLabel() {
            const labels = {
                'monthly': '/month',
                'quarterly': '/3 months',
                'semi-annual': '/6 months',
                'annual': '/year'
            };
            return labels[document.querySelector('select[name="billing_cycle"]')?.value] || '/month';
        }
    };
}
</script>
@endsection
