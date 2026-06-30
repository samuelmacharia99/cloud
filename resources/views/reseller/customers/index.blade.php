@extends('layouts.reseller')

@section('title', 'My Customers')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Customers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                @if ($usesDirectAdminDirectory ?? false)
                    All hosted users from DirectAdmin, with platform link and billing status.
                @else
                    Manage your customer accounts and subscriptions.
                @endif
            </p>
        </div>
        <a href="{{ route('reseller.customers.create') }}" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Customer
        </a>
    </div>

    <!-- Package Limits Usage -->
    @if ($resellerPackage)
    <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-slate-900 dark:text-white">{{ ($hostedUserCountSource ?? 'portal') === 'directadmin' ? 'Hosted User Quota (DirectAdmin)' : 'Your Customer Quota' }}</h3>
            <span class="text-sm text-purple-700 dark:text-purple-300 font-medium">{{ $customerCount }} / {{ $resellerPackage->max_users }}</span>
        </div>
        @php
            $customerPct = $resellerPackage->max_users > 0
                ? min(100, round(($customerCount / $resellerPackage->max_users) * 100))
                : 0;
            $customerColor = $customerPct >= 90 ? 'bg-red-500' : ($customerPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
        @endphp
        <div class="w-full h-3 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
            <div class="{{ $customerColor }} h-3 rounded-full transition-all" style="width: {{ $customerPct }}%"></div>
        </div>
        <p class="text-xs text-purple-700 dark:text-purple-300 mt-2">
            @if(($hostedUserCountSource ?? 'portal') === 'directadmin')
                {{ max(0, $resellerPackage->max_users - $customerCount) }} more hosted user slot(s) on DirectAdmin (includes accounts created outside this portal)
            @else
                You can create {{ max(0, $resellerPackage->max_users - $customerCount) }} more customer(s)
            @endif
        </p>
    </div>
    @endif

    @if ($usesDirectAdminDirectory ?? false)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Total users</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ $directoryStats['total'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">On DirectAdmin</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ $directoryStats['directadmin_total'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-emerald-200 dark:border-emerald-800 p-4">
                <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Linked</p>
                <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-300 mt-1">{{ $directoryStats['linked'] ?? 0 }}</p>
            </div>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-amber-200 dark:border-amber-800 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Unlinked</p>
                <p class="text-2xl font-bold text-amber-700 dark:text-amber-300 mt-1">{{ $directoryStats['unlinked'] ?? 0 }}</p>
            </div>
        </div>
    @endif

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, DA username, domain..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            @if ($usesDirectAdminDirectory ?? false)
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Platform link</label>
                    <select name="link" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                        <option value="all">All</option>
                        <option value="linked" @selected(request('link') === 'linked')>Linked</option>
                        <option value="unlinked" @selected(request('link') === 'unlinked')>Unlinked</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing</label>
                    <select name="billing" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-sm">
                        <option value="all">All</option>
                        <option value="ready" @selected(request('billing') === 'ready')>Auto-billing ready</option>
                        <option value="package_detected" @selected(request('billing') === 'package_detected')>Package detected</option>
                        <option value="needs_package" @selected(request('billing') === 'needs_package')>Needs package</option>
                    </select>
                </div>
            @endif
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            @if ($usesDirectAdminDirectory ?? false)
                <a href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium rounded-lg transition text-sm">Refresh from DirectAdmin</a>
            @endif
        </div>
    </form>

    <!-- Table -->
    <div
        class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden"
        @if ($usesDirectAdminDirectory ?? false)
            x-data="directAdminCustomerDirectory(@js([
                'listings' => ($catalogListings ?? collect())->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values(),
                'customers' => ($managedCustomers ?? collect())->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'email' => $c->email])->values(),
                'linkUrl' => route('reseller.directadmin-accounts.link'),
                'isAdmin' => false,
            ]))"
            @open-da-link-modal.window="openLink($event.detail)"
        @endif
    >
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        @if ($usesDirectAdminDirectory ?? false)
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">DA user</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Domain</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Package</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Link</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Billing</th>
                        @endif
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Company</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Services</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoices</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($customers as $customer)
                        @if ($usesDirectAdminDirectory ?? false)
                            @include('partials.customer-directory-row', ['row' => $customer, 'context' => 'reseller', 'catalogListings' => $catalogListings ?? collect()])
                        @else
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white text-sm font-semibold">
                                        {{ strtoupper(substr($customer->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $customer->name }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">{{ $customer->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ $customer->company ?: '-' }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                    {{ ucfirst($customer->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                {{ $customer->services_count }}
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                {{ $customer->invoices_count }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <form method="POST" action="{{ route('reseller.customers.impersonate', $customer) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition" title="Login as this customer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                            </svg>
                                        </button>
                                    </form>
                                    <a href="{{ route('reseller.customers.show', $customer) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition" title="View customer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    <a href="{{ route('reseller.customers.edit', $customer) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition" title="Edit customer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </a>
                                    <form method="POST" action="{{ route('reseller.customers.destroy', $customer) }}" class="inline" data-confirm='Are you sure you want to delete this customer?'>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition" title="Delete customer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ ($usesDirectAdminDirectory ?? false) ? 11 : 6 }}" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No customers found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if ($usesDirectAdminDirectory ?? false)
        <form method="POST" action="{{ route('reseller.directadmin-accounts.bulk-link') }}" id="bulk-da-link-form" class="flex flex-wrap items-center gap-3 mb-4">
            @csrf
            <input type="hidden" name="billing_cycle" value="annual">
            <input type="hidden" name="country" value="KE">
            <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg">Bulk link selected</button>
            <p class="text-xs text-slate-500">Select unlinked DirectAdmin accounts on this page, then bulk link with your default annual billing cycle.</p>
        </form>

        <div x-show="linkModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div class="bg-white dark:bg-slate-900 rounded-xl shadow-xl w-full max-w-lg p-6" @click.outside="linkModalOpen = false">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Link DirectAdmin account</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="linkForm.da_username ? 'User: ' + linkForm.da_username : ''"></p>
                <form method="POST" :action="linkUrl" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="da_username" x-model="linkForm.da_username">
                    <div>
                        <label class="block text-sm font-medium mb-1">Create new customer or link existing</label>
                        <select name="customer_id" x-model="linkForm.customer_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            <option value="">Create new customer</option>
                            <template x-for="customer in customers" :key="customer.id">
                                <option :value="customer.id" x-text="customer.name + ' (' + customer.email + ')'"></option>
                            </template>
                        </select>
                    </div>
                    <div x-show="!linkForm.customer_id" class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Name</label>
                            <input type="text" name="name" x-model="linkForm.name" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Email</label>
                            <input type="email" name="email" x-model="linkForm.email" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Country</label>
                            <input type="text" name="country" value="KE" maxlength="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Catalog package</label>
                        <select name="reseller_product_id" x-model="linkForm.reseller_product_id" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            <option value="">Auto-detect from DirectAdmin package</option>
                            <template x-for="listing in listings" :key="listing.id">
                                <option :value="listing.id" x-text="listing.name"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Billing cycle</label>
                        <select name="billing_cycle" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            <option value="annual">Annual</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-annual</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="linkModalOpen = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-purple-600 hover:bg-purple-700 text-white">Link account</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function directAdminCustomerDirectory(config) {
                return {
                    linkModalOpen: false,
                    linkUrl: config.linkUrl,
                    listingsByReseller: config.listingsByReseller || {},
                    customersByReseller: config.customersByReseller || {},
                    listings: config.listings || [],
                    customers: config.customers || [],
                    linkForm: {
                        da_username: '',
                        name: '',
                        email: '',
                        customer_id: '',
                        reseller_id: '',
                        reseller_product_id: '',
                    },
                    get activeListings() {
                        if (config.isAdmin) {
                            return this.listingsByReseller[this.linkForm.reseller_id] || [];
                        }

                        return this.listings;
                    },
                    get activeCustomers() {
                        if (config.isAdmin) {
                            return this.customersByReseller[this.linkForm.reseller_id] || [];
                        }

                        return this.customers;
                    },
                    openLink(detail) {
                        this.linkForm = {
                            da_username: detail.da_username || '',
                            name: detail.display_name || '',
                            email: detail.display_email || '',
                            customer_id: '',
                            reseller_id: detail.reseller_id ? String(detail.reseller_id) : '',
                            reseller_product_id: detail.matched_listing_id ? String(detail.matched_listing_id) : '',
                        };
                        this.linkModalOpen = true;
                    },
                };
            }
        </script>
    @endif

    <div class="mt-6">
        {{ $customers->links() }}
    </div>
</div>
@endsection
