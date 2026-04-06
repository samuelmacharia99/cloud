<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Find Your Domain — {{ config('app.name', 'Talksasa Cloud') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="antialiased bg-white" x-data="domainSearch()">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-white/95 backdrop-blur-md border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <a href="/" class="flex items-center gap-2 hover:opacity-75 transition">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center">
                        <span class="text-white font-bold">TC</span>
                    </div>
                    <span class="text-xl font-bold text-gray-900">Talksasa</span>
                </a>
            </div>

            <div class="flex items-center gap-4">
                <a href="{{ route('login') }}" class="hidden sm:inline text-gray-700 hover:text-blue-600 transition font-medium">Login</a>
                <a href="/" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">Back Home</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50 via-blue-50 to-slate-50 min-h-screen">
        <div class="max-w-4xl mx-auto">
            <!-- Search Header -->
            <div class="mb-12 text-center">
                <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">Find Your Perfect Domain</h1>
                <p class="text-xl text-gray-600 mb-8">Search millions of domains and get started in minutes</p>

                <!-- Search Form -->
                <div class="flex gap-2 max-w-2xl mx-auto">
                    <div class="flex-1 relative">
                        <input
                            type="text"
                            x-model="searchQuery"
                            @keydown.enter="searchDomain()"
                            placeholder="e.g., google.com or google"
                            class="w-full px-6 py-4 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition text-lg"
                            required
                        >
                    </div>
                    <button
                        @click="searchDomain()"
                        class="px-8 py-4 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition disabled:opacity-50"
                        :disabled="loading"
                    >
                        <span x-show="!loading">Search</span>
                        <span x-show="loading" class="inline-block">Searching...</span>
                    </button>
                </div>

                <p class="text-center text-sm text-gray-500 mt-4">
                    Type full domain (google.com) or just the name (google)
                </p>
            </div>

            <!-- Results Section -->
            <div x-show="resultsDisplayed" class="space-y-6">
                <!-- Available Domains -->
                <template x-if="availableDomains.length > 0">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100 text-green-600 font-bold">✓</span>
                            Available Domains (<span x-text="availableDomains.length"></span>)
                        </h2>

                        <div class="space-y-3">
                            <template x-for="domain in availableDomains" :key="domain.full_domain">
                                <div class="bg-white rounded-xl border border-green-200 p-6 hover:shadow-lg transition">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3 mb-2">
                                                <h3 class="text-2xl font-bold text-gray-900 font-mono">
                                                    <span x-text="domain.full_domain"></span>
                                                </h3>
                                                <span class="inline-block px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-semibold">
                                                    Available
                                                </span>
                                            </div>
                                            <p class="text-gray-600">1 year registration</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-3xl font-bold text-gray-900">
                                                KES <span x-text="formatPrice(domain.price)"></span>
                                            </p>
                                            <p class="text-sm text-gray-500">per year</p>
                                        </div>
                                    </div>

                                    <div class="mt-4 pt-4 border-t border-gray-200 flex gap-3">
                                        <button
                                            @click="addToCart(domain)"
                                            class="flex-1 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition"
                                        >
                                            Add to Cart
                                        </button>
                                        <button
                                            @click="addToCart(domain); goToCheckout()"
                                            class="flex-1 px-4 py-2.5 border-2 border-blue-600 text-blue-600 rounded-lg font-semibold hover:bg-blue-50 transition"
                                        >
                                            Buy Now
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Unavailable Domains -->
                <template x-if="unavailableDomains.length > 0">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-bold">✗</span>
                            Unavailable Domains (<span x-text="unavailableDomains.length"></span>)
                        </h2>

                        <div class="space-y-3">
                            <template x-for="domain in unavailableDomains" :key="domain.full_domain">
                                <div class="bg-white rounded-xl border border-gray-200 p-6 opacity-60">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3 mb-2">
                                                <h3 class="text-2xl font-bold text-gray-900 font-mono">
                                                    <span x-text="domain.full_domain"></span>
                                                </h3>
                                                <span class="inline-block px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-semibold">
                                                    Unavailable
                                                </span>
                                            </div>
                                            <p class="text-gray-600">This domain is already registered</p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- No Results -->
                <template x-if="resultsDisplayed && availableDomains.length === 0 && unavailableDomains.length === 0">
                    <div class="bg-white rounded-xl border-2 border-dashed border-gray-300 p-12 text-center">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">No domains found</h3>
                        <p class="text-gray-600">Try a different search term</p>
                    </div>
                </template>

                <!-- Alternative Suggestions -->
                <template x-if="resultsDisplayed && availableDomains.length === 0">
                    <div class="bg-blue-50 rounded-xl border border-blue-200 p-8">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Couldn't find what you're looking for?</h3>
                        <p class="text-gray-700 mb-4">Try these alternatives:</p>
                        <div class="space-y-2 text-gray-700">
                            <p>• Try different extensions (.co.ke, .net, .org, .io)</p>
                            <p>• Add numbers or hyphens to make it unique</p>
                            <p>• Contact us at support@talksasa.cloud for premium domains</p>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Empty State -->
            <template x-if="!resultsDisplayed">
                <div class="text-center py-12">
                    <svg class="w-24 h-24 text-gray-300 mx-auto mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <p class="text-gray-500 text-lg">Start by searching for a domain above</p>
                </div>
            </template>

            <!-- Checkout Button (appears when items in cart) -->
            <template x-if="resultsDisplayed && cartCount > 0">
                <div class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-2xl">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Items in cart: <span x-text="cartCount" class="font-bold text-gray-900"></span></p>
                            <p class="text-lg font-bold text-gray-900">Total: KES <span x-text="formatPrice(cartTotal)"></span></p>
                        </div>
                        <button
                            @click="goToCheckout()"
                            class="px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition"
                        >
                            Proceed to Checkout
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </section>

    <!-- Toast Notifications -->
    <div class="fixed bottom-24 right-4 z-40 space-y-2" x-data="{}">
        <template x-for="toast in $store.toasts || []" :key="toast.id">
            <div
                :class="{
                    'bg-green-500': toast.type === 'success',
                    'bg-red-500': toast.type === 'error',
                    'bg-blue-500': toast.type === 'info'
                }"
                class="text-white px-6 py-3 rounded-lg shadow-lg"
            >
                <span x-text="toast.message"></span>
            </div>
        </template>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-400 py-12 px-4 sm:px-6 lg:px-8 mt-20">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center">
                <p>&copy; 2026 Talksasa Cloud. All rights reserved.</p>
                <div class="flex gap-4">
                    <a href="/" class="hover:text-white transition">Home</a>
                    <a href="#" class="hover:text-white transition">Privacy</a>
                    <a href="#" class="hover:text-white transition">Contact</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        function domainSearch() {
            return {
                searchQuery: '',
                results: [],
                loading: false,
                resultsDisplayed: false,

                get availableDomains() {
                    return this.results.filter(r => r.available);
                },

                get unavailableDomains() {
                    return this.results.filter(r => !r.available);
                },

                get cartCount() {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    return cart.length;
                },

                get cartTotal() {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    return cart.reduce((sum, item) => sum + (item.price || 0), 0);
                },

                async searchDomain() {
                    if (!this.searchQuery.trim()) return;

                    this.loading = true;
                    this.results = [];

                    try {
                        const url = new URL('{{ route('domains.search.public') }}', window.location.origin);
                        url.searchParams.append('q', this.searchQuery);

                        const response = await fetch(url.toString(), {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();

                        if (data.success) {
                            this.results = data.results;
                            this.resultsDisplayed = true;
                        } else {
                            this.showToast(data.message || 'Error searching domains', 'error');
                        }
                    } catch (error) {
                        console.error('Search error:', error);
                        this.showToast('Failed to search domains. Please try again.', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                addToCart(domain) {
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');

                    // Check if domain already in cart
                    if (cart.find(d => d.full_domain === domain.full_domain)) {
                        this.showToast('Domain already in cart', 'info');
                        return;
                    }

                    cart.push(domain);
                    localStorage.setItem('domainCart', JSON.stringify(cart));
                    this.showToast(`${domain.full_domain} added to cart!`, 'success');
                },

                goToCheckout() {
                    window.location.href = '{{ route("checkout.show.public") }}';
                },

                formatPrice(price) {
                    return new Intl.NumberFormat('en-US', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0,
                    }).format(price);
                },

                showToast(message, type = 'info') {
                    // Simple toast notification
                    const toast = document.createElement('div');
                    const bgColor = {
                        success: 'bg-green-500',
                        error: 'bg-red-500',
                        info: 'bg-blue-500'
                    }[type];

                    toast.className = `${bgColor} text-white px-6 py-3 rounded-lg shadow-lg fixed bottom-24 right-4 z-40`;
                    toast.textContent = message;
                    document.body.appendChild(toast);

                    setTimeout(() => toast.remove(), 3000);
                }
            }
        }
    </script>
</body>
</html>
