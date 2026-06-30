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
        <p class="text-slate-600 dark:text-slate-400 mt-1">Register or transfer domains for your account (wholesale) or bill a managed customer at your retail prices.</p>
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
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8" x-data="domainSearchManager(@js(['customerMode' => ($cartContext['mode'] ?? 'self') === 'customer', 'knownExtensions' => $knownExtensions]))">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Register a New Domain</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6" x-show="!customerMode">
            Prices shown are your <strong class="text-purple-700 dark:text-purple-300">wholesale</strong> rates for your account.
        </p>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6" x-show="customerMode" x-cloak>
            Prices shown are <strong class="text-purple-700 dark:text-purple-300">retail</strong> rates for billing the selected customer.
        </p>

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
                        <button type="button" @click="searchDomains()"
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
                        Results for <strong x-text="domainName"></strong>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <template x-for="(result, idx) in searchResults" :key="idx">
                            <div class="bg-white dark:bg-slate-900 border rounded-lg p-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3"
                                :class="result.domainAvailable ? 'border-emerald-200 dark:border-emerald-800/60' : 'border-slate-200 dark:border-slate-700'">
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-medium text-slate-900 dark:text-white font-mono" x-text="result.domain"></p>
                                        <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                                            :class="result.domainAvailable ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                            x-text="result.domainAvailable ? 'Available' : 'Taken'"></span>
                                    </div>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-show="result.domainAvailable">
                                        <span class="text-xs font-semibold uppercase tracking-wide mr-1" :class="result.retail ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'" x-text="result.retail ? 'Retail' : 'Wholesale'"></span>
                                        <span x-text="result.currency + ' ' + parseFloat(result.lineTotal).toFixed(2)"></span>
                                        <span class="text-xs text-slate-500" x-text="'for ' + selectedPeriod + ' year' + (selectedPeriod > 1 ? 's' : '')"></span>
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5" x-show="result.domainAvailable">
                                        <span x-text="result.currency + ' ' + parseFloat(result.price).toFixed(2)"></span>
                                        <span>/ year</span>
                                        <template x-if="!result.retail && result.wholesalePrice">
                                            <span class="ml-1">(your cost)</span>
                                        </template>
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-show="!result.domainAvailable">
                                        This domain is not available to register.
                                    </p>
                                </div>
                                <button type="button" @click="addToCart(result.domain, result.extension, result.price, selectedPeriod)"
                                    x-show="result.domainAvailable"
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
                    <p class="text-slate-600 dark:text-slate-400 text-sm">No results for this search. Check the domain format and extension.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Domain Transfer Section -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8" x-data="domainTransferManager(@js(['customerMode' => ($cartContext['mode'] ?? 'self') === 'customer', 'knownExtensions' => $knownExtensions]))">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Transfer a Domain to Us</h2>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6" x-show="!customerMode">
            Move a domain you own elsewhere into your reseller account at <strong class="text-purple-700 dark:text-purple-300">wholesale transfer</strong> rates.
        </p>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6" x-show="customerMode" x-cloak>
            Bill a managed customer for a domain transfer at your <strong class="text-purple-700 dark:text-purple-300">retail transfer</strong> price.
        </p>

        <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6 text-sm text-blue-900 dark:text-blue-300">
            You need the <strong>EPP / auth code</strong> from your current registrar. Unlock the domain there before submitting the transfer.
        </div>

        <div x-show="transferMessage" x-transition class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 rounded-lg mb-4">
            <p class="text-sm text-emerald-900 dark:text-emerald-300" x-text="transferMessage"></p>
        </div>

        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain name</label>
                    <input type="text" x-model="domainName" placeholder="example"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                    <p class="text-xs text-slate-500 mt-1">Without the extension (e.g. <code class="font-mono">mycompany</code> for mycompany.com)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                    <select x-model="selectedExtension" @change="loadTransferPricing()"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                        <option value="">Select TLD</option>
                        @foreach ($extensions as $ext)
                            <option value="{{ $ext->extension }}">{{ $ext->extension }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div x-show="transferPrice !== null" x-cloak class="p-3 bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800 rounded-lg">
                <p class="text-sm font-medium text-purple-900 dark:text-purple-200">
                    Transfer cost:
                    <span class="font-bold" x-text="currency + ' ' + (transferPrice !== null ? parseFloat(transferPrice).toFixed(2) : '—')"></span>
                    <span class="text-xs font-semibold uppercase ml-2" :class="retail ? 'text-amber-600' : 'text-emerald-600'" x-text="retail ? 'Retail' : 'Wholesale'"></span>
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">EPP / auth code</label>
                    <input type="text" x-model="eppCode" placeholder="From your current registrar"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current registrar</label>
                    <input type="text" x-model="oldRegistrar" placeholder="e.g. GoDaddy, Namecheap"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar website <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="url" x-model="oldRegistrarUrl" placeholder="https://"
                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
            </div>

            <div class="flex items-start gap-2">
                <input type="checkbox" x-model="confirmTransfer" id="resellerConfirmTransfer" class="mt-1 rounded border-slate-300 text-purple-600">
                <label for="resellerConfirmTransfer" class="text-sm text-slate-600 dark:text-slate-400">
                    I have the EPP code and will unlock the domain at my current registrar.
                </label>
            </div>

            <button type="button" @click="addTransferToCart()" :disabled="adding || !confirmTransfer"
                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white font-medium rounded-lg transition text-sm">
                <span x-show="!adding">Add transfer to cart</span>
                <span x-show="adding">Adding…</span>
            </button>
        </div>
    </div>

    <!-- My Domains Section -->
    <div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Your Domains</h2>

        @if($domains->count() > 0)
            <div class="ui-card">
                <div class="ui-table-wrap overflow-visible">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th>Expires</th>
                                <th>Auto renew</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($domains as $domain)
                                <tr>
                                    <td>
                                        <a href="{{ route('reseller.domains.show', $domain) }}" class="font-semibold text-purple-700 dark:text-purple-300 hover:underline font-mono text-sm">{{ $domain->name }}{{ $domain->extension }}</a>
                                    </td>
                                    <td class="text-slate-600 dark:text-slate-400">
                                        @if($domain->user_id !== auth()->id())
                                            <span class="font-medium text-slate-900 dark:text-white">{{ $domain->user->name }}</span>
                                            <p class="text-xs text-slate-500">{{ $domain->user->email }}</p>
                                        @else
                                            <span class="text-slate-400">My account</span>
                                        @endif
                                    </td>
                                    <td><x-domain-status-badge :status="$domain->status" /></td>
                                    <td class="text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ $domain->registered_at?->format('M d, Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap">
                                        <span class="{{ $domain->isExpired() ? 'text-red-600 dark:text-red-400 font-medium' : 'text-slate-600 dark:text-slate-400' }}">
                                            {{ $domain->expires_at?->format('M d, Y') ?? '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-semibold {{ $domain->auto_renew ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400' }}">
                                            {{ $domain->auto_renew ? 'On' : 'Off' }}
                                        </span>
                                    </td>
                                    <td class="text-right overflow-visible">
                                        <div x-data="{ open: false, showRenewal: false, renewYears: '1', renewing: false }" class="relative inline-block text-left z-10">
                                            <button type="button" @click="open = !open" class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Domain actions">
                                                <svg fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                                </svg>
                                            </button>
                                            <div x-show="open" x-cloak @click.outside="open = false; showRenewal = false"
                                                class="absolute right-0 mt-1 w-56 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                                                <div x-show="!showRenewal">
                                                    <button type="button" @click="showRenewal = true"
                                                        class="w-full text-left px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-950/40 transition flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 text-sm font-medium text-purple-700 dark:text-purple-300">
                                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                        </svg>
                                                        Renew domain
                                                    </button>
                                                    <a href="{{ route('reseller.domains.show', $domain) }}"
                                                        class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition text-sm font-medium text-slate-700 dark:text-slate-300 border-b border-slate-100 dark:border-slate-800">
                                                        Manage domain
                                                    </a>
                                                    <a href="{{ route('reseller.cart.index') }}"
                                                        class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition text-sm font-medium text-slate-700 dark:text-slate-300 border-b border-slate-100 dark:border-slate-800">
                                                        View cart
                                                    </a>
                                                    <form method="POST" action="{{ route('reseller.domains.destroy', $domain) }}"
                                                        data-confirm="Remove {{ $domain->name }}{{ $domain->extension }} from your account? This only deletes the local record; it does not cancel registration at the registry."
                                                        @submit="open = false">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                            class="w-full text-left px-4 py-3 hover:bg-red-50 dark:hover:bg-red-950/40 transition flex items-center gap-3 text-sm font-medium text-red-600 dark:text-red-400">
                                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                            </svg>
                                                            Delete domain
                                                        </button>
                                                    </form>
                                                </div>
                                                <div x-show="showRenewal" class="p-4">
                                                    <button type="button" @click="showRenewal = false" class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-3 hover:text-slate-700 dark:hover:text-slate-200">← Back</button>
                                                    <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">Renewal period</p>
                                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
                                                        @if (($cartContext['mode'] ?? 'self') === 'customer')
                                                            Prices use your <strong>renewal retail</strong> rates for the selected customer.
                                                        @else
                                                            Prices use your <strong>wholesale renewal</strong> rates.
                                                        @endif
                                                    </p>
                                                    <div class="space-y-2 mb-4">
                                                        @foreach($periods as $period)
                                                            <label class="flex items-center gap-2 p-2 rounded-lg border cursor-pointer text-sm transition"
                                                                :class="renewYears == '{{ $period }}' ? 'border-purple-400 bg-purple-50 dark:bg-purple-950/40 dark:border-purple-600' : 'border-slate-200 dark:border-slate-700'">
                                                                <input type="radio" value="{{ $period }}" x-model="renewYears" class="text-purple-600">
                                                                {{ $period }} year{{ $period > 1 ? 's' : '' }}
                                                            </label>
                                                        @endforeach
                                                    </div>
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
                                                        class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-medium rounded-lg transition text-sm">
                                                        <span x-show="!renewing">Add renewal to cart</span>
                                                        <span x-show="renewing">Adding…</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">
                {{ $domains->links() }}
            </div>
        @else
            <div class="ui-card">
                <div class="p-12 text-center">
                    <svg class="w-16 h-16 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>
                    </svg>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">No domains yet</h3>
                    <p class="text-slate-600 dark:text-slate-400">Use the search above to register your first domain.</p>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
function domainSearchManager({ customerMode = false, knownExtensions = [] } = {}) {
    return {
        customerMode: Boolean(customerMode),
        knownExtensions: [...knownExtensions].sort((a, b) => b.length - a.length),
        domainName: '',
        selectedPeriod: '1',
        searching: false,
        searchPerformed: false,
        searchResults: [],
        adding: false,
        addedMessage: '',

        resolveExtension(input) {
            const known = this.knownExtensions.find((ext) => input.endsWith(ext));
            if (known) {
                return known;
            }

            // Fallback when extension list is unavailable: use last label as TLD
            const lastDot = input.lastIndexOf('.');
            if (lastDot > 0) {
                return input.substring(lastDot);
            }

            return null;
        },

        pricingApiUrl(extension) {
            const base = '{{ url('api/reseller/domains/pricing') }}';
            return `${base}/${encodeURIComponent(extension)}`;
        },

        availabilityApiUrl(domain) {
            return '{{ route('reseller.domains.check') }}?domain=' + encodeURIComponent(domain);
        },

        async searchDomains() {
            const input = this.domainName.trim().toLowerCase();

            if (!input) {
                alert('Please enter a domain name');
                return;
            }

            if (!input.includes('.')) {
                alert('Please enter a full domain name with extension (e.g., example.com or example.co.ke)');
                return;
            }

            const extension = this.resolveExtension(input);
            if (!extension) {
                alert('Extension not supported. Try a TLD from your domain pricing list (e.g., .com, .co.ke).');
                return;
            }

            const domainName = input.slice(0, -extension.length);
            if (!domainName || domainName.endsWith('.')) {
                alert('Invalid domain name');
                return;
            }

            this.searching = true;
            this.searchPerformed = true;
            this.searchResults = [];

            try {
                const availabilityRes = await fetch(this.availabilityApiUrl(input), {
                    headers: { 'Accept': 'application/json' },
                });

                if (!availabilityRes.ok) {
                    const errorData = await availabilityRes.json().catch(() => ({}));
                    alert(errorData.message || 'Could not check this domain. Try a supported extension.');
                    return;
                }

                const availabilityData = await availabilityRes.json();
                const domainAvailable = Boolean(availabilityData.available);

                let unitPrice = 0;
                let lineTotal = 0;
                let wholesalePrice = 0;
                let currency = 'KES';
                let retail = this.customerMode;

                if (domainAvailable) {
                    const retailParam = this.customerMode ? '&retail=1' : '';
                    const pricingRes = await fetch(
                        this.pricingApiUrl(extension) + '?period=' + this.selectedPeriod + retailParam,
                        { headers: { 'Accept': 'application/json' } }
                    );

                    if (!pricingRes.ok) {
                        alert('Extension not found or pricing unavailable');
                        return;
                    }

                    const pricingData = await pricingRes.json();

                    if (!pricingData.available) {
                        alert('Wholesale pricing is not configured for this extension and period.');
                        return;
                    }

                    const period = parseInt(this.selectedPeriod, 10);
                    lineTotal = parseFloat(pricingData.line_total ?? 0);
                    unitPrice = parseFloat(pricingData.price ?? (lineTotal / period));
                    wholesalePrice = parseFloat(pricingData.wholesale_price ?? unitPrice);
                    currency = pricingData.currency || 'KES';
                    retail = Boolean(pricingData.retail);
                }

                this.searchResults.push({
                    domain: input,
                    extension: extension,
                    price: unitPrice,
                    lineTotal: lineTotal,
                    wholesalePrice: wholesalePrice,
                    currency: currency,
                    domainAvailable: domainAvailable,
                    retail: retail,
                });
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
                const resolvedExtension = this.resolveExtension(domain) || extension || domain.substring(domain.lastIndexOf('.'));
                const domainName = domain.slice(0, -resolvedExtension.length);

                const res = await fetch('{{ route("reseller.cart.add") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: JSON.stringify({
                        domain: domainName,
                        extension: resolvedExtension,
                        years: parseInt(period, 10),
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

function domainTransferManager({ customerMode = false, knownExtensions = [] } = {}) {
    return {
        customerMode: Boolean(customerMode),
        knownExtensions,
        domainName: '',
        selectedExtension: '',
        eppCode: '',
        oldRegistrar: '',
        oldRegistrarUrl: '',
        confirmTransfer: false,
        transferPrice: null,
        currency: 'KES',
        retail: false,
        adding: false,
        transferMessage: '',

        pricingApiUrl(extension) {
            const base = '{{ url('api/reseller/domains/pricing') }}';
            return `${base}/${encodeURIComponent(extension)}`;
        },

        async loadTransferPricing() {
            this.transferPrice = null;
            if (!this.selectedExtension) {
                return;
            }

            try {
                const retailParam = this.customerMode ? '&retail=1' : '';
                const res = await fetch(
                    this.pricingApiUrl(this.selectedExtension) + '?type=transfer' + retailParam,
                    { headers: { 'Accept': 'application/json' } }
                );

                if (!res.ok) {
                    return;
                }

                const data = await res.json();
                if (!data.available) {
                    alert('Transfer pricing is not configured for this extension.');
                    return;
                }

                this.transferPrice = parseFloat(data.line_total ?? data.price ?? 0);
                this.currency = data.currency || 'KES';
                this.retail = Boolean(data.retail);
            } catch (e) {
                console.error('Transfer pricing error:', e);
            }
        },

        async addTransferToCart() {
            const name = this.domainName.trim().toLowerCase();
            if (!name || !this.selectedExtension) {
                alert('Enter the domain name and select an extension.');
                return;
            }

            if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/.test(name)) {
                alert('Enter a valid domain name (letters, numbers, hyphens).');
                return;
            }

            if (!this.eppCode || this.eppCode.length < 5) {
                alert('Enter the EPP / auth code from your current registrar.');
                return;
            }

            if (!this.oldRegistrar || this.oldRegistrar.length < 2) {
                alert('Enter your current registrar name.');
                return;
            }

            if (!this.confirmTransfer) {
                alert('Confirm you have the EPP code and will unlock the domain.');
                return;
            }

            if (this.transferPrice === null) {
                await this.loadTransferPricing();
            }

            if (this.transferPrice === null || this.transferPrice <= 0) {
                alert('Transfer pricing is not available for this extension.');
                return;
            }

            this.adding = true;

            try {
                const res = await fetch('{{ route('reseller.cart.transfer') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: JSON.stringify({
                        domain: name,
                        extension: this.selectedExtension,
                        price: this.transferPrice,
                        epp_code: this.eppCode,
                        old_registrar: this.oldRegistrar,
                        old_registrar_url: this.oldRegistrarUrl || null,
                    })
                });

                const data = await res.json();
                if (!res.ok || !data.success) {
                    alert(data.message || 'Could not add transfer to cart');
                    return;
                }

                const badge = document.getElementById('cart-count-badge');
                if (badge) {
                    badge.textContent = data.item_count;
                    badge.classList.remove('hidden');
                }

                this.transferMessage = `${name}${this.selectedExtension} transfer added to cart.`;
                setTimeout(() => this.transferMessage = '', 4000);

                this.domainName = '';
                this.eppCode = '';
                this.oldRegistrar = '';
                this.oldRegistrarUrl = '';
                this.confirmTransfer = false;
            } catch (e) {
                alert('Error: ' + e.message);
            } finally {
                this.adding = false;
            }
        }
    }
}
</script>
@endsection
