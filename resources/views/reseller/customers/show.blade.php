@extends('layouts.reseller')

@section('title', $customer->name)

@section('content')
<div class="space-y-6" x-data="resellerCustomerPage()">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $customer->name }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $customer->email }}</p>
        </div>
        <div class="flex flex-wrap gap-2 justify-end">
            <form method="POST" action="{{ route('reseller.customers.impersonate', $customer) }}" class="inline">
                @csrf
                <button type="submit" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition text-sm">View as customer</button>
            </form>
            <button type="button" @click="addDomainModal = true" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition text-sm">Add domain</button>
            <button type="button" @click="addServiceModal = true" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition text-sm">Add service</button>
            <a href="{{ route('reseller.domains.index', ['customer' => $customer->id]) }}" class="px-4 py-2 border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 font-medium rounded-lg transition text-sm">Register domain</a>
            <a href="{{ route('reseller.customer-invoices.create', ['customer' => $customer->id]) }}" class="px-4 py-2 border border-purple-300 text-purple-700 font-medium rounded-lg transition text-sm">New invoice</a>
            <a href="{{ route('reseller.customers.edit', $customer) }}" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg transition text-sm">Edit</a>
        </div>
    </div>

    <!-- Key Info Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <!-- Services -->
        <div class="ui-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->services->where('status', 'active')->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Invoices -->
        <div class="ui-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->invoices->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div class="ui-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Account Status</p>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium mt-2 {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                        {{ ucfirst($customer->status) }}
                    </span>
                </div>
                <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <svg class="w-6 h-6 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Domains -->
        <div class="ui-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Domains</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customer->domains->count() }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <svg class="w-6 h-6 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    @if (!empty($enforcementAlerts))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Enforcement alerts</h2>
            <ul class="space-y-2">
                @foreach ($enforcementAlerts as $alert)
                    <li class="text-sm px-3 py-2 rounded-lg {{ $alert['level'] === 'danger' ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' }}">
                        <a href="{{ route('reseller.services.show', $alert['service_id']) }}" class="font-medium hover:underline">{{ $alert['service_name'] }}</a>
                        — {{ $alert['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Customer Details -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Contact Info -->
        <div class="ui-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Contact Information</h2>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Email</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->email }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Phone</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->phone ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Company</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->company ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">Country</p>
                    <p class="text-slate-900 dark:text-white">{{ \App\Support\Countries::display($customer->country) }}</p>
                </div>
                <div>
                    <p class="text-slate-600 dark:text-slate-400 text-xs uppercase font-medium">City</p>
                    <p class="text-slate-900 dark:text-white">{{ $customer->city ?: '-' }}</p>
                </div>
            </div>
        </div>

        <!-- Services (left column spans 2) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Services List -->
            <div class="ui-card overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Services</h2>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $customer->services->count() }} total</span>
                        <button type="button" @click="addServiceModal = true" class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">Add service</button>
                    </div>
                </div>
                @if ($customer->services->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->services as $service)
                            <div class="flex items-center gap-2 p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <a href="{{ route('reseller.services.show', $service) }}" class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $service->customerPlanName() }} • {{ ucfirst($service->billing_cycle) }}</p>
                                        </div>
                                        <div class="flex items-center gap-3 ml-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $service->status->value === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($service->status->value === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                                {{ ucfirst($service->status->value) }}
                                            </span>
                                        </div>
                                    </div>
                                </a>
                                <button
                                    type="button"
                                    @click="openEditService({{ $service->id }})"
                                    class="shrink-0 px-3 py-1.5 text-xs font-medium text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/50 hover:bg-purple-100 dark:hover:bg-purple-950 rounded-lg transition"
                                >
                                    Edit
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center space-y-3">
                        <p class="text-slate-500 dark:text-slate-400">No services yet</p>
                        <button type="button" @click="addServiceModal = true" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">Add service</button>
                    </div>
                @endif
            </div>

            <!-- Domains List -->
            <div class="ui-card overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Domains</h2>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $customer->domains->count() }} total</span>
                        <button type="button" @click="addDomainModal = true" class="px-3 py-1.5 text-xs font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition">Add domain</button>
                    </div>
                </div>
                @if ($customer->domains->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->domains as $domain)
                            <a href="{{ route('reseller.domains.show', $domain) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900 dark:text-white truncate">{{ $domain->name }}{{ $domain->extension }}</p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                                            {{ $domain->expires_at ? 'Expires '.$domain->expires_at->format('M d, Y') : 'No expiry set' }}
                                            @if ($domain->cloudflare_dns_enabled)
                                                · Managed DNS
                                            @endif
                                        </p>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $domain->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400' }}">
                                        {{ ucfirst($domain->status) }}
                                    </span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center space-y-3">
                        <p class="text-slate-500 dark:text-slate-400">No domains yet</p>
                        <button type="button" @click="addDomainModal = true" class="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition">Add domain</button>
                    </div>
                @endif
            </div>

            <!-- Recent Invoices -->
            <div class="ui-card overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                </div>
                @if ($customer->invoices->count() > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->invoices->take(5) as $invoice)
                            <a href="{{ route('reseller.customer-invoices.show', $invoice) }}" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $invoice->created_at->format('M d, Y') }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</p>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1 {{ $invoice->status->value === 'paid' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($invoice->status->value === 'unpaid' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                                        {{ ucfirst($invoice->status->value) }}
                                    </span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="p-12 text-center">
                        <p class="text-slate-500 dark:text-slate-400">No invoices yet</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Notes Section -->
    @if ($customer->notes)
    <div class="ui-card p-6">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notes</h2>
        <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed">{{ $customer->notes }}</p>
    </div>
    @endif

    <!-- Add Domain Modal -->
    <div
        x-show="addDomainModal"
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        @keydown.escape.window="addDomainModal = false"
    >
        <div class="fixed inset-0 bg-black/50" @click="addDomainModal = false"></div>
        <div class="flex min-h-full items-end sm:items-center justify-center p-4">
            <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden" @click.stop>
                <div class="flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add domain</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Manually attach a domain to {{ $customer->name }}</p>
                    </div>
                    <button type="button" @click="addDomainModal = false" class="text-slate-500 hover:text-slate-800 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('reseller.customers.add-domain', $customer) }}" class="p-6 space-y-4 overflow-y-auto flex-1">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Domain name <span class="text-red-500">*</span></label>
                        <input type="text" name="domain_name" value="{{ old('domain_name') }}" required placeholder="example.co.ke"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        @error('domain_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status <span class="text-red-500">*</span></label>
                        <select name="status" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                            <option value="pending" @selected(old('status') === 'pending')>Pending</option>
                            <option value="expired" @selected(old('status') === 'expired')>Expired</option>
                            <option value="suspended" @selected(old('status') === 'suspended')>Suspended</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Registered</label>
                            <input type="date" name="registered_at" value="{{ old('registered_at') }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Expires <span class="text-red-500">*</span></label>
                            <input type="date" name="expires_at" value="{{ old('expires_at', now()->addYear()->format('Y-m-d')) }}" required
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 1</label>
                            <input type="text" name="nameserver_1" value="{{ old('nameserver_1') }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 2</label>
                            <input type="text" name="nameserver_2" value="{{ old('nameserver_2') }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" name="auto_renew" value="1" @checked(old('auto_renew')) class="rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                        Auto-renew
                    </label>
                    @if ($cloudflareDnsAvailable ?? false)
                        <label class="inline-flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="checkbox" name="enable_cloudflare_dns" value="1" @checked(old('enable_cloudflare_dns', true)) class="mt-0.5 rounded border-slate-300 text-purple-600 focus:ring-purple-500">
                            <span>Enable managed DNS (platform Cloudflare)</span>
                        </label>
                    @endif
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">{{ old('notes') }}</textarea>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="addDomainModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-700 dark:text-slate-300">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium">Add domain</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div
        x-show="addServiceModal"
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        @keydown.escape.window="addServiceModal = false"
    >
        <div class="fixed inset-0 bg-black/50" @click="addServiceModal = false"></div>
        <div class="flex min-h-full items-end sm:items-center justify-center p-4">
            <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden" @click.stop>
                <div class="flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add service</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Assign a catalog plan to {{ $customer->name }}</p>
                    </div>
                    <button type="button" @click="addServiceModal = false" class="text-slate-500 hover:text-slate-800 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('reseller.customers.add-service', $customer) }}" class="p-6 space-y-4 overflow-y-auto flex-1">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Catalog product <span class="text-red-500">*</span></label>
                        <select name="reseller_product_id" x-model="addServiceProductId" required
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <option value="">Select a plan…</option>
                            <template x-for="product in catalogProducts" :key="product.id">
                                <option :value="String(product.id)" x-text="catalogLabel(product)"></option>
                            </template>
                        </select>
                        @error('reseller_product_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        @if (empty($catalogProductsForJs))
                            <p class="text-xs text-amber-600 mt-1">No active catalog products. <a href="{{ route('reseller.catalog.create') }}" class="underline">Create one</a> first.</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing cycle <span class="text-red-500">*</span></label>
                        <select name="billing_cycle" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <option value="monthly" @selected(old('billing_cycle', 'monthly') === 'monthly')>Monthly</option>
                            <option value="quarterly" @selected(old('billing_cycle') === 'quarterly')>Quarterly</option>
                            <option value="semi-annual" @selected(old('billing_cycle') === 'semi-annual')>Semi-annual</option>
                            <option value="annual" @selected(old('billing_cycle') === 'annual')>Annual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Order type <span class="text-red-500">*</span></label>
                        <select name="order_type" x-model="addServiceOrderType" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <option value="provision">Create service (provision when paid / immediately if no charge)</option>
                            <option value="invoice_only">Invoice only (no service yet)</option>
                        </select>
                    </div>
                    <div x-show="addServiceNeedsDomain()">
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Primary domain <span class="text-red-500">*</span></label>
                        <input type="text" name="primary_domain" value="{{ old('primary_domain') }}" placeholder="example.com"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        @error('primary_domain') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div x-show="addServiceOrderType === 'invoice_only' || addServiceBillCustomer">
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Invoice due date</label>
                        <input type="date" name="due_date" value="{{ old('due_date') }}"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                    </div>
                    <label class="inline-flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="hidden" name="bill_customer" :value="addServiceBillCustomer ? '1' : '0'">
                        <input type="checkbox" x-model="addServiceBillCustomer" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Bill the customer (uncheck to add at no charge / without a customer invoice)</span>
                    </label>
                    <div>
                        <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white">{{ old('notes') }}</textarea>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="addServiceModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-700 dark:text-slate-300">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium" :disabled="catalogProducts.length === 0">Add service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Service Modal -->
<div
    x-show="editServiceModal"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-service-modal-title"
    @keydown.escape.window="editServiceModal = false"
>
    <div class="fixed inset-0 bg-black/50" @click="editServiceModal = false"></div>
    <div class="flex min-h-full items-end sm:items-center justify-center p-4">
        <div
            x-show="editServiceModal"
            x-transition
            class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden"
            @click.stop
        >
            <div class="flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800">
                <div>
                    <h2 id="edit-service-modal-title" class="text-xl font-bold text-slate-900 dark:text-white">Edit service</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="editServiceName"></p>
                </div>
                <button type="button" @click="editServiceModal = false" class="shrink-0 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form :action="'{{ url('reseller/services') }}/' + editServiceId" method="POST" class="p-6 space-y-5 overflow-y-auto flex-1 min-h-0">
                @csrf
                @method('PATCH')
                <input type="hidden" name="return_to" value="customer">

                <div>
                    <label for="edit_reseller_product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package / product</label>
                    <select id="edit_reseller_product_id" name="reseller_product_id" x-model="editResellerProductId" required
                            @change="onEditCatalogChange()"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <template x-for="product in editServiceCatalogProducts()" :key="product.id">
                            <option :value="String(product.id)" x-text="catalogLabel(product)"></option>
                        </template>
                    </select>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">For shared hosting, the DirectAdmin package is updated on the server when you save.</p>
                </div>

                <div>
                    <label for="edit_billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing cycle</label>
                    <select id="edit_billing_cycle" name="billing_cycle" x-model="editBillingCycle" required
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="semi-annual">Semi-annual</option>
                        <option value="annual">Annual</option>
                    </select>
                </div>

                <div>
                    <label for="edit_custom_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Custom price (KES)</label>
                    <input type="number" id="edit_custom_price" name="custom_price" step="0.01" min="0" x-model="editCustomPrice"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                           placeholder="Leave empty to use catalog price">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="edit_commenced_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Commenced</label>
                        <input type="date" id="edit_commenced_at" name="commenced_at" x-model="editCommencedAt"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    <div>
                        <label for="edit_next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next due date</label>
                        <input type="date" id="edit_next_due_date" name="next_due_date" x-model="editNextDueDate" required
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                </div>

                <template x-if="showDirectAdminFields()">
                    <div class="space-y-4 pt-1 border-t border-slate-200 dark:border-slate-800">
                        <p class="text-sm font-medium text-slate-900 dark:text-white" x-text="editHasHostingAccount ? 'DirectAdmin account' : 'Link DirectAdmin account'"></p>
                        <div>
                            <label for="edit_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Username</label>
                            <input type="text" id="edit_username" name="username" x-model="editUsername" autocomplete="off"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   :placeholder="editHasHostingAccount ? 'Current account username' : 'Existing DA username to link'">
                        </div>
                        <div>
                            <label for="edit_primary_domain" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Primary domain</label>
                            <input type="text" id="edit_primary_domain" name="primary_domain" x-model="editDomain"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   placeholder="example.com">
                        </div>
                        <div>
                            <label for="edit_password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password</label>
                            <input type="password" id="edit_password" name="password" autocomplete="new-password"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                   placeholder="Leave blank to keep unchanged">
                        </div>
                    </div>
                </template>

                <div class="flex gap-3 pt-2">
                    <button type="button" @click="editServiceModal = false"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition">
                        Save changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resellerCustomerPage() {
    return {
        addDomainModal: @json($errors->has('domain_name') || old('domain_name')),
        addServiceModal: @json($errors->has('reseller_product_id') || $errors->has('primary_domain') || old('reseller_product_id')),
        addServiceProductId: @json(old('reseller_product_id', '')),
        addServiceOrderType: @json(old('order_type', 'provision')),
        addServiceBillCustomer: @json(old('bill_customer', true) ? true : false),
        editServiceModal: false,
        editServiceId: null,
        editServiceName: '',
        editResellerProductId: '',
        editProductType: '',
        editBillingCycle: 'monthly',
        editCustomPrice: '',
        editCommencedAt: '',
        editNextDueDate: '',
        editIsDirectAdmin: false,
        editHasHostingAccount: false,
        editUsername: '',
        editDomain: '',
        services: @json($servicesForJs),
        catalogProducts: @json($catalogProductsForJs),

        addServiceNeedsDomain() {
            const product = this.catalogProducts.find(p => String(p.id) === String(this.addServiceProductId));
            return !!product?.requires_primary_domain || !!product?.uses_direct_admin_package;
        },

        openEditService(serviceId) {
            const service = this.services.find(s => s.id === serviceId);
            if (!service) return;

            this.editServiceId = service.id;
            this.editServiceName = service.name;
            this.editResellerProductId = service.reseller_product_id ? String(service.reseller_product_id) : '';
            this.editProductType = service.product_type || '';
            this.editBillingCycle = service.billing_cycle || 'monthly';
            this.editCustomPrice = service.custom_price ?? '';
            this.editCommencedAt = service.commenced_at || '';
            this.editNextDueDate = service.next_due_date || '';
            this.editIsDirectAdmin = !!service.is_directadmin;
            this.editHasHostingAccount = !!service.has_hosting_account;
            this.editUsername = service.username || '';
            this.editDomain = service.domain || '';

            if (!this.editResellerProductId) {
                const fallback = this.editServiceCatalogProducts()[0];
                if (fallback) {
                    this.editResellerProductId = String(fallback.id);
                }
            }

            this.editServiceModal = true;
        },

        onEditCatalogChange() {
            const product = this.catalogProducts.find(p => String(p.id) === String(this.editResellerProductId));
            if (product?.uses_direct_admin_package) {
                this.editIsDirectAdmin = true;
            }
        },

        showDirectAdminFields() {
            if (this.editIsDirectAdmin) {
                return true;
            }

            const product = this.catalogProducts.find(p => String(p.id) === String(this.editResellerProductId));

            return !!product?.uses_direct_admin_package;
        },

        editServiceCatalogProducts() {
            if (!this.editProductType) return this.catalogProducts;
            return this.catalogProducts.filter(p => p.type === this.editProductType);
        },

        catalogLabel(product) {
            const price = product.monthly_price
                ? ` — KES ${Number(product.monthly_price).toLocaleString()}/mo`
                : '';
            const da = product.uses_direct_admin_package && product.direct_admin_package_name
                ? ` (${product.direct_admin_package_name})`
                : '';
            return `${product.name}${da}${price}`;
        },
    };
}
</script>
</div>
@endsection
