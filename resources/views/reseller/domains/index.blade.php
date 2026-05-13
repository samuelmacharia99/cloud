@extends('layouts.reseller')

@section('title', 'My Domains')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">My Domains</p>
</div>
@endsection

@section('content')
<div class="space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Domains</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your domain portfolio and register new domains at wholesale rates.</p>
    </div>

    <!-- Domain Search Section -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8" x-data="domainSearchManager()">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-6">Register a New Domain</h2>

        <div class="space-y-6">
            <!-- Search Form -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
                    <div class="flex gap-2">
                        <input type="text"
                            x-model="domainName"
                            @keyup.enter="searchDomains()"
                            placeholder="e.g., example.com"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        <button @click="searchDomains()"
                            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Search
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Period</label>
                    <select x-model="selectedPeriod" @change="searchDomains()"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        <option value="1">1 Year</option>
                        <option value="2">2 Years</option>
                        <option value="3">3 Years</option>
                        <option value="5">5 Years</option>
                        <option value="10">10 Years</option>
                    </select>
                </div>
            </div>

            <!-- Search Results -->
            <div x-show="searchPerformed" x-transition class="mt-6 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                <div x-show="searching" class="flex items-center justify-center gap-2">
                    <div class="w-4 h-4 bg-blue-600 rounded-full animate-bounce"></div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Checking domain availability...</p>
                </div>

                <div x-show="!searching && searchResults.length > 0">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">
                        Available domains for <strong x-text="domainName"></strong>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <template x-for="(result, idx) in searchResults" :key="idx">
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg p-4 flex justify-between items-center">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white" x-text="result.domain"></p>
                                    <p class="text-sm text-slate-600 dark:text-slate-400">
                                        <span x-text="'$' + parseFloat(result.price).toFixed(2)"></span>
                                        <span class="text-xs text-slate-500">/ year</span>
                                    </p>
                                </div>
                                <button @click="addToCart(result.domain, result.extension, result.price, selectedPeriod)"
                                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg text-sm transition">
                                    Add
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="!searching && searchResults.length === 0" class="text-center py-4">
                    <p class="text-slate-600 dark:text-slate-400 text-sm">No available domains found for this search.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- My Domains Section -->
    <div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Your Domains</h2>

        @if($domains->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($domains as $domain)
                    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden hover:shadow-lg dark:hover:shadow-2xl transition">
                        <!-- Card Header -->
                        <div class="bg-gradient-to-r {{ $domain->status === 'active' ? 'from-emerald-500 to-emerald-600' : ($domain->status === 'expired' ? 'from-red-500 to-red-600' : 'from-amber-500 to-amber-600') }} p-4">
                            <h3 class="text-lg font-bold text-white break-all">{{ $domain->name }}{{ $domain->extension }}</h3>
                        </div>

                        <!-- Card Content -->
                        <div class="p-6 space-y-4">
                            <!-- Owner (if customer's domain) -->
                            @if($domain->user_id !== auth()->id())
                                <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-700">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Customer</span>
                                    <span class="text-sm text-slate-900 dark:text-white font-medium">{{ $domain->user->name }}</span>
                                </div>
                            @endif

                            <!-- Status Badge -->
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Status</span>
                                <span class="px-3 py-1 rounded-full text-xs font-medium {{ $domain->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($domain->status === 'expired' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300') }}">
                                    {{ ucfirst($domain->status) }}
                                </span>
                            </div>

                            <!-- Registration Date -->
                            @if($domain->registered_at)
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Registered</span>
                                    <span class="text-sm text-slate-900 dark:text-white">{{ $domain->registered_at->format('M d, Y') }}</span>
                                </div>
                            @endif

                            <!-- Expiry Date -->
                            @if($domain->expires_at)
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Expires</span>
                                    <span class="text-sm {{ $domain->isExpired() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-slate-900 dark:text-white' }}">
                                        {{ $domain->expires_at->format('M d, Y') }}
                                    </span>
                                </div>
                            @endif

                            <!-- Registrar -->
                            @if($domain->registrar)
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Registrar</span>
                                    <span class="text-sm text-slate-900 dark:text-white">{{ $domain->registrar }}</span>
                                </div>
                            @endif

                            <!-- Auto Renew -->
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Auto Renew</span>
                                <span class="text-sm">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium {{ $domain->auto_renew ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' }}">
                                        {{ $domain->auto_renew ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex gap-2">
                            <a href="#" class="flex-1 text-center px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-slate-700 rounded-lg transition">
                                View
                            </a>
                            <a href="#" class="flex-1 text-center px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition">
                                Renew
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="mt-8">
                {{ $domains->links() }}
            </div>
        @else
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
                <svg class="w-16 h-16 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"></path>
                </svg>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">No Domains Yet</h3>
                <p class="text-slate-600 dark:text-slate-400 mb-4">You haven't registered any domains. Use the search above to get started!</p>
            </div>
        @endif
    </div>
</div>

<script>
function domainSearchManager() {
    return {
        domainName: '',
        selectedPeriod: '1',
        searching: false,
        searchPerformed: false,
        searchResults: [],

        async searchDomains() {
            const input = this.domainName.trim().toLowerCase();

            if (!input) {
                alert('Please enter a domain name');
                return;
            }

            // Check if input contains a dot (extension)
            if (!input.includes('.')) {
                alert('Please enter a full domain name with extension (e.g., example.com)');
                return;
            }

            // Extract domain name and extension
            const lastDotIndex = input.lastIndexOf('.');
            const domainName = input.substring(0, lastDotIndex);
            const extension = input.substring(lastDotIndex);

            if (!domainName) {
                alert('Invalid domain name');
                return;
            }

            this.searching = true;
            this.searchPerformed = true;
            this.searchResults = [];

            try {
                // Get pricing for this extension (pass with dot, e.g., .com)
                const pricingRes = await fetch(
                    `{{ route('reseller.domains.pricing.api', ['extension' => ':extension']) }}`.replace(':extension', extension),
                    {
                        headers: {
                            'Accept': 'application/json',
                        }
                    }
                );

                if (pricingRes.ok) {
                    const pricingData = await pricingRes.json();
                    if (pricingData.available) {
                        // For demo, assume domain is available
                        const price = pricingData.retail_price || pricingData.wholesale_price;
                        this.searchResults.push({
                            domain: input,
                            extension: extension,
                            price: price * this.selectedPeriod,
                            available: true
                        });
                    }
                } else {
                    alert('Extension not found or pricing unavailable');
                }
            } catch (error) {
                console.error('Search error:', error);
                alert('Error searching domains. Please try again.');
            } finally {
                this.searching = false;
            }
        },

        addToCart(domain, extension, price, period) {
            // Get existing cart or create new one
            let cart = JSON.parse(localStorage.getItem('cart') || '[]');

            // Extract domain name (without extension)
            const domainName = domain.substring(0, domain.lastIndexOf('.'));

            // Add domain to cart
            cart.push({
                type: 'domain',
                domain: domainName,
                extension: extension,
                full_domain: domain,
                years: parseInt(period),
                price: price
            });

            // Save to localStorage
            localStorage.setItem('cart', JSON.stringify(cart));

            // Show success message
            alert(`${domain} added to cart!`);

            // Optionally redirect to checkout
            // window.location.href = '{{ route("checkout.show.public") }}';
        }
    }
}
</script>
@endsection
