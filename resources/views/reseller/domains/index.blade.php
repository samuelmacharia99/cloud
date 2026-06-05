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
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Domains</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Register for your account (wholesale) or bill a managed customer at your retail prices.</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
        <form method="POST" action="{{ route('reseller.cart.context') }}" class="flex flex-wrap gap-3 items-end">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-slate-500 mb-1">Cart billing mode</label>
                <select name="customer_id" class="w-full px-3 py-2 border rounded-lg bg-white dark:bg-slate-800 text-sm">
                    <option value="">My account (wholesale)</option>
                    @foreach ($cartCustomers as $c)
                        <option value="{{ $c->id }}" @selected(($cartContext['customer_id'] ?? null) == $c->id)>{{ $c->name }} (customer retail)</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium">Apply</button>
        </form>
        @if (($cartContext['mode'] ?? 'self') === 'customer')
            <p class="text-xs text-purple-600 mt-2">Cart checkout will create a customer invoice at retail for {{ $cartContext['customer_name'] ?? 'selected customer' }}.</p>
        @endif
    </div>

    <!-- Domain Search Section -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8" x-data="domainSearchManager({{ ($cartContext['mode'] ?? 'self') === 'customer' ? 'true' : 'false' }})">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-6">Register a New Domain</h2>

        <div class="space-y-6">
            <!-- Success Flash Message -->
            <div x-show="addedMessage" x-transition class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                <p class="text-sm text-emerald-900 dark:text-emerald-300" x-text="addedMessage"></p>
            </div>

            <!-- Search Form -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
                    <div class="flex gap-2">
                        <input type="text"
                            x-model="domainName"
                            @keyup.enter="searchDomains()"
                            placeholder="e.g., example.com"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                        <button @click="searchDomains()"
                            class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                            Search
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Period</label>
                    <select x-model="selectedPeriod" @change="searchDomains()"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
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
                    <div class="w-4 h-4 bg-purple-600 rounded-full animate-bounce"></div>
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
                                        <span x-text="result.currency + ' ' + parseFloat(result.price).toFixed(2)"></span>
                                        <span class="text-xs text-slate-500">/ year</span>
                                    </p>
                                </div>
                                <button @click="addToCart(result.domain, result.extension, result.price, selectedPeriod)"
                                    :disabled="adding"
                                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:bg-slate-400 text-white font-medium rounded-lg text-sm transition">
                                    <span x-show="!adding">Add to Cart</span>
                                    <span x-show="adding">Adding...</span>
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
                        <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex gap-2"
                             x-data="{ renewing: false, renewYears: '1' }">
                            <a href="{{ route('reseller.cart.index') }}" class="flex-1 text-center px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-slate-700 rounded-lg transition">
                                Cart
                            </a>
                            <button type="button"
                                    @click="async () => {
                                        renewing = true;
                                        try {
                                            const res = await fetch('{{ route('reseller.domains.renew', $domain) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'Accept': 'application/json',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                                                },
                                                body: JSON.stringify({ years: parseInt(renewYears, 10) })
                                            });
                                            const data = await res.json();
                                            if (data.success) {
                                                const badge = document.getElementById('cart-count-badge');
                                                if (badge) {
                                                    badge.textContent = data.item_count;
                                                    badge.classList.remove('hidden');
                                                }
                                                window.location.href = data.redirect || '{{ route('reseller.cart.index') }}';
                                            } else {
                                                alert(data.message || 'Could not add renewal to cart');
                                            }
                                        } catch (e) {
                                            alert('Error: ' + e.message);
                                        } finally {
                                            renewing = false;
                                        }
                                    }"
                                    :disabled="renewing"
                                    class="flex-1 text-center px-3 py-2 text-sm font-medium text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-slate-700 rounded-lg transition disabled:opacity-50">
                                <span x-show="!renewing">Renew</span>
                                <span x-show="renewing">Adding...</span>
                            </button>
                            <select x-model="renewYears" class="w-20 px-2 py-2 text-xs border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                                @foreach($periods as $period)
                                    <option value="{{ $period }}">{{ $period }}y</option>
                                @endforeach
                            </select>
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
function domainSearchManager(customerMode = false) {
    return {
        customerMode: Boolean(customerMode),
        domainName: '',
        selectedPeriod: '1',
        searching: false,
        searchPerformed: false,
        searchResults: [],
        adding: false,
        addedMessage: '',

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
                const retailParam = this.customerMode ? '&retail=1' : '';
                const pricingRes = await fetch(
                    `{{ route('reseller.domains.pricing.api', ['extension' => ':extension']) }}`.replace(':extension', extension)
                        + '?period=' + this.selectedPeriod + retailParam,
                    {
                        headers: {
                            'Accept': 'application/json',
                        }
                    }
                );

                if (pricingRes.ok) {
                    const pricingData = await pricingRes.json();
                    if (pricingData.available) {
                        const lineTotal = pricingData.line_total ?? (pricingData.price * this.selectedPeriod);
                        const unitPrice = lineTotal / this.selectedPeriod;
                        this.searchResults.push({
                            domain: input,
                            extension: extension,
                            price: unitPrice,
                            lineTotal: lineTotal,
                            currency: pricingData.currency || 'KSH',
                            available: true,
                            retail: pricingData.retail || this.customerMode,
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

        async addToCart(domain, extension, price, period) {
            this.adding = true;

            try {
                // Extract domain name (without extension)
                const domainName = domain.substring(0, domain.lastIndexOf('.'));

                const res = await fetch('{{ route("reseller.cart.add") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: JSON.stringify({
                        domain: domainName,
                        extension: extension,
                        years: parseInt(period),
                        price: price
                    })
                });

                if (!res.ok) {
                    const contentType = res.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const errorData = await res.json();
                        console.error('Server error:', errorData);
                        alert(errorData.message || 'Failed to add to cart');
                    } else {
                        const errorText = await res.text();
                        console.error('Server error:', errorText);
                        alert('Server error: ' + res.status + ' ' + res.statusText);
                    }
                    return;
                }

                const data = await res.json();
                if (data.success) {
                    // Update nav cart badge
                    const badge = document.getElementById('cart-count-badge');
                    if (badge) {
                        badge.textContent = data.item_count;
                        badge.classList.remove('hidden');
                    }

                    // Show success message
                    this.addedMessage = `${domain} added to cart!`;
                    setTimeout(() => this.addedMessage = '', 3000);
                } else {
                    alert(data.message || 'Failed to add to cart');
                }
            } catch (error) {
                console.error('Cart error:', error);
                alert('Error adding to cart: ' + error.message);
            } finally {
                this.adding = false;
            }
        }
    }
}
</script>
@endsection
