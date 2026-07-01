@extends('layouts.admin')

@section('title', $customer->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.customers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Customers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $customer->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="initCustomerData()">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div class="flex items-start gap-4">
                <!-- Avatar -->
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xl font-semibold flex-shrink-0">
                    {{ strtoupper(substr($customer->name, 0, 1)) }}
                </div>

                <!-- Header info -->
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $customer->name }}</h1>
                    <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $customer->email }}</p>

                    <!-- Status badges -->
                    <div class="flex items-center gap-3 mt-3">
                        <!-- Account status -->
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $customer->status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : ($customer->status === 'suspended' ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400') }}">
                            {{ ucfirst($customer->status) }}
                        </span>

                        <!-- Account type -->
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                            {{ !empty($customer->company) ? 'Company' : 'Individual' }}
                        </span>

                        <!-- Owner -->
                        @if($customer->reseller)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300">
                                Managed by <a href="{{ route('admin.resellers.show', $customer->reseller) }}" class="underline ml-1">{{ $customer->reseller->name }}</a>
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                Platform (direct)
                            </span>
                        @endif

                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 dark:bg-cyan-950 text-cyan-700 dark:text-cyan-300">
                            Billing: {{ $customerCurrencySymbol }} ({{ $customerCurrencyCode }})
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.customers.impersonate', $customer) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition text-sm">
                        Impersonate
                    </button>
                </form>
                <a href="{{ route('admin.customers.edit', $customer) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Customer
                </a>
                <button @click="createInvoiceModal = true" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                    Create Invoice
                </button>
                <form action="{{ route('admin.customers.destroy', $customer) }}" method="POST" class="inline" data-confirm='Are you sure you want to delete this customer? This action cannot be undone.'>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                        Delete Customer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-slate-200 dark:border-slate-800">
        <div class="flex gap-8 overflow-x-auto">
            <button @click="tab = 'overview'" :class="tab === 'overview' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Overview
            </button>
            <button @click="tab = 'services'" :class="tab === 'services' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Services
            </button>
            <button @click="tab = 'invoices'" :class="tab === 'invoices' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Invoices
            </button>
            <button @click="tab = 'payments'" :class="tab === 'payments' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Payments
            </button>
            <button @click="tab = 'credits'" :class="tab === 'credits' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Credits
                @if ($creditAvailableBalance > 0)
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-950 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                        KES {{ number_format($creditAvailableBalance, 0) }}
                    </span>
                @endif
            </button>
            <button @click="tab = 'tickets'" :class="tab === 'tickets' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Tickets
            </button>
            <button @click="tab = 'activity'" :class="tab === 'activity' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Activity
            </button>
        </div>
    </div>

    <!-- Tab Content -->

    <!-- Overview Tab -->
    <div x-show="tab === 'overview'" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Profile Info Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Profile Information</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Email Address</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->email }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Phone</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->phone ?: '-' }}</p>
                    </div>
                    @if ($customer->company)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Company</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->company }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Country</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ \App\Support\Countries::display($customer->country) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Address</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->address ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">City</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->city ?: '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Postal Code</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->postal_code ?: '-' }}</p>
                    </div>
                    @if ($customer->vat_number)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">VAT Number</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->vat_number }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Account Summary Card -->
            <div class="space-y-6">
                <!-- Stats -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Account Summary</h3>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Member Since</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->created_at->format('M d, Y') }}</p>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Active Services</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->services_count ?? 0 }}</p>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Total Invoices</p>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $customer->invoices_count ?? 0 }}</p>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Account credit</p>
                            <p class="text-sm font-medium text-emerald-600 dark:text-emerald-400">KES {{ number_format($creditAvailableBalance, 2) }}</p>
                        </div>
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-slate-600 dark:text-slate-400">Outstanding Balance</p>
                            <p class="text-sm font-medium text-amber-600 dark:text-amber-400">$0.00</p>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                @if ($customer->notes)
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notes</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ $customer->notes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Services Tab -->
    <div x-show="tab === 'services'" class="space-y-6">
        @php
            $customerDomainsByFqdn = $customer->domains->keyBy(fn ($d) => strtolower($d->fqdn()));
        @endphp
        <!-- Action Buttons -->
        <div class="flex gap-3">
            <button @click="console.log('[Button] Add Service clicked'), addServiceModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                + Add Service
            </button>
            <button @click="addDomainModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                + Add Domain
            </button>
        </div>

        <!-- Services Section -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                @if ($customer->services->count() > 0)
                    <table class="w-full">
                        <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Service</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Product</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Billing Cycle</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Commenced</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Next Due</th>
                                <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($customer->services as $service)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                        {{ $service->name }}
                                        @if(($service->product?->type === 'shared_hosting') && !empty($service->service_meta['username']))
                                            <div class="text-xs font-normal text-slate-500 dark:text-slate-400 mt-0.5">
                                                <span class="font-mono">{{ $service->service_meta['username'] }}</span>
                                                @if(!empty($service->service_meta['domain']))
                                                    @php $serviceDomain = $customerDomainsByFqdn->get(strtolower($service->service_meta['domain'])); @endphp
                                                    &middot;
                                                    @if($serviceDomain)
                                                        <a href="{{ route('admin.domains.show', $serviceDomain) }}" class="font-mono text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline">{{ $service->service_meta['domain'] }}</a>
                                                    @else
                                                        <span class="font-mono">{{ $service->service_meta['domain'] }}</span>
                                                    @endif
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->product->name ?? '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('-', ' ', $service->billing_cycle)) }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($service->status->value === 'active') bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                            @elseif($service->status->value === 'suspended') bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                            @elseif($service->status->value === 'terminated') bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                            @else bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400 @endif">
                                            {{ ucfirst($service->status->value) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->commenced_at ? $service->commenced_at->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->next_due_date ? $service->next_due_date->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-4">
                                            <button type="button"
                                                @click="openEditService({{ $service->id }})"
                                                class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white text-sm font-medium transition-colors">
                                                Edit
                                            </button>
                                            <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                            @if (!in_array($service->status->value, ['cancelled', 'terminated']))
                                            <button type="button"
                                                @click="cancelServiceModal = true; cancelServiceId = {{ $service->id }}; cancelServiceName = '{{ addslashes($service->name) }}'"
                                                class="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 text-sm font-medium transition-colors">
                                                Cancel
                                            </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-6 py-12 text-center">
                        <p class="text-slate-600 dark:text-slate-400">No services found.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Domains Section -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Domains</h3>
            </div>
            <div class="overflow-x-auto">
                @if ($customer->domains->count() > 0)
                    <table class="w-full">
                        <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Domain</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Registered</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Expires</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                                <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($customer->domains as $domain)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium">
                                        <a href="{{ route('admin.domains.show', $domain) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline">
                                            {{ $domain->fqdn() }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $domain->registered_at ? $domain->registered_at->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $domain->expires_at ? $domain->expires_at->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($domain->status === 'active') bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                            @elseif($domain->status === 'expired') bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                            @else bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400 @endif">
                                            {{ ucfirst($domain->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('admin.domains.show', $domain) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-6 py-12 text-center">
                        <p class="text-slate-600 dark:text-slate-400">No domains found.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Invoices Tab -->
    <div x-show="tab === 'invoices'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->invoices->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice Number</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Due Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Total</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->invoices->sortByDesc('created_at') as $invoice)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->due_date?->format('M d, Y') ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</td>
                                <td class="px-6 py-4">
                                    <x-status-badge :status="$invoice->status" type="invoice" />
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('admin.invoices.show', $invoice) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No invoices found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Credits Tab -->
    <div x-show="tab === 'credits'" x-cloak>
        @include('admin.customers.partials.credits-tab', [
            'customer' => $customer,
            'customerCredits' => $customerCredits,
            'creditAvailableBalance' => $creditAvailableBalance,
        ])
    </div>

    <!-- Payments Tab -->
    <div x-show="tab === 'payments'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->payments->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Payment Method</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->payments as $payment)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $payment->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">KSH {{ number_format($payment->amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst($payment->gateway ?? 'Manual') }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Completed
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No payments found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Tickets Tab -->
    <div x-show="tab === 'tickets'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            @if ($customer->tickets->count() > 0)
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Ticket ID</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Subject</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Priority</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($customer->tickets as $ticket)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ str_pad($ticket->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-900 dark:text-white">{{ $ticket->subject }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Normal
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Open
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $ticket->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="#" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No tickets found.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Activity Tab -->
    <div x-show="tab === 'activity'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="text-center py-12">
            <p class="text-slate-600 dark:text-slate-400">Full activity log coming soon.</p>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div
        x-show="addServiceModal"
        x-cloak
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-service-modal-title"
        @keydown.escape.window="addServiceModal = false"
    >
        <div class="fixed inset-0 bg-black/50" @click="addServiceModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div
                x-show="addServiceModal"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:scale-95"
                class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 max-h-[min(90vh,56rem)] flex flex-col"
                @click.stop
            >
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-800 shrink-0">
                    <div class="pr-4">
                        <h2 id="add-service-modal-title" class="text-xl font-bold text-slate-900 dark:text-white">Add Service</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            Prices shown in <span class="font-medium text-slate-700 dark:text-slate-300">{{ $customerCurrencySymbol }} ({{ $customerCurrencyCode }})</span> — from {{ $customer->name }}'s profile
                        </p>
                    </div>
                    <button type="button" @click="addServiceModal = false" class="shrink-0 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.customers.add-service', $customer) }}" class="p-6 space-y-6 overflow-y-auto flex-1 min-h-0" @submit="onAddServiceSubmit($event)">
            @csrf

            <!-- Product Type Selection -->
            <div>
                <label for="product_type" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Type <span class="text-red-500">*</span></label>
                <select id="product_type" x-model="selectedProductType" @change="onProductTypeChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="">Select a product type</option>
                    <template x-for="type in getProductTypes()" :key="type">
                        <option :value="type" x-text="type.replace(/_/g, ' ').replace(/\\b\\w/g, l => l.toUpperCase())"></option>
                    </template>
                </select>
            </div>

            <!-- Non-Shared Hosting Product Selection -->
            <template x-if="selectedProductType && selectedProductType !== 'shared_hosting'">
                <div>
                    <label for="product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product <span class="text-red-500">*</span></label>
                    <select id="product_id" name="product_id" x-model="selectedProduct" @change="onProductChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                        <option value="">Select a product</option>
                        <template x-for="product in getProductsByType()" :key="product.id">
                            <option :value="product.id" x-text="product.name"></option>
                        </template>
                    </select>
                </div>
            </template>

            <!-- Shared Hosting: Server → Product → Package -->
            <template x-if="selectedProductType === 'shared_hosting'">
                <div class="space-y-4 border-t border-slate-200 dark:border-slate-800 pt-4">
                    <!-- Server Selection -->
                    <div>
                        <label for="node_select" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">DirectAdmin Server <span class="text-red-500">*</span></label>
                        <select id="node_select" x-model="selectedNodeId" @change="onNodeChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                            <option value="">Choose a server...</option>
                            <template x-for="node in daNodes" :key="node.id">
                                <option :value="node.id" x-text="node.name + ' (' + node.hostname + ')'"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Product Selection (for Shared Hosting) -->
                    <div>
                        <label for="product_id_sh" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product <span class="text-red-500">*</span></label>
                        <select id="product_id_sh" name="product_id" x-model="selectedProduct" @change="onProductChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                            <option value="">Select a product</option>
                            <template x-for="product in getProductsByType()" :key="product.id">
                                <option :value="product.id" x-text="product.name"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Package Selection (Dropdown) -->
                    <template x-if="selectedNodeId">
                        <div>
                            <label for="package_select" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">DirectAdmin Package <span class="text-red-500">*</span></label>
                            <select id="package_select" x-model="selectedPackageId" @change="onPackageChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" :disabled="loadingPackages" required>
                                <option value="" x-show="loadingPackages">Loading packages...</option>
                                <option value="" x-show="!loadingPackages">Choose a package...</option>
                                <template x-for="pkg in nodePackages" :key="pkg.id">
                                    <option :value="pkg.id" x-show="!loadingPackages" x-text="pkg.name + ' (' + pkg.disk_quota + ' GB disk, ' + pkg.bandwidth_quota + ' GB bandwidth)'"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Service Name -->
            <div>
                <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Service Name <span class="text-red-500">*</span></label>
                <input type="text" id="name" name="name" x-model="productName" placeholder="e.g., My Web Hosting" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>

            <!-- Billing Cycle -->
            <div>
                <label for="billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle <span class="text-red-500">*</span></label>
                <select id="billing_cycle" name="billing_cycle" x-model="billingCycle" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="semi-annual">Semi-Annual</option>
                    <option value="annual">Annual</option>
                </select>
            </div>

            <!-- Pricing -->
            <template x-if="selectedProduct">
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">Standard product price</p>
                            <p class="text-lg font-bold text-slate-900 dark:text-white mt-1" x-text="formatCustomerMoney(catalogPriceDisplay())"></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="'Per ' + billingCycleLabel()"></p>
                        </div>
                        <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300" x-text="customerCurrency"></span>
                    </div>

                    <div>
                        <label for="custom_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Custom price (optional)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 text-sm font-medium" x-text="customerCurrencySymbol"></span>
                            <input
                                type="number"
                                id="custom_price"
                                name="custom_price"
                                x-model="customPrice"
                                step="0.01"
                                min="0"
                                placeholder="Leave empty to use standard price"
                                class="w-full pl-14 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white"
                            >
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Overrides the product price for this service and all future renewal invoices.
                            <span x-show="customPrice" class="block mt-1 text-slate-600 dark:text-slate-300">
                                Invoice amount: <span class="font-medium" x-text="formatCustomerMoney(parseFloat(customPrice) || 0)"></span>
                            </span>
                        </p>
                        @error('custom_price')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </template>

            <!-- Status -->
            <div>
                <label for="status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status <span class="text-red-500">*</span></label>
                <select id="status" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="provisioning">Provisioning</option>
                    <option value="suspended">Suspended</option>
                    <option value="terminated">Terminated</option>
                </select>
            </div>

            <!-- DirectAdmin Credentials (Shared Hosting) -->
            <template x-if="isSharedHosting()">
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">DirectAdmin Account <span class="text-red-500">*</span></h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">cPanel-style username + password</span>
                </div>
                <div class="space-y-4">
                    <!-- Username -->
                    <div>
                        <label for="direct_admin_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Username <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input type="text" id="direct_admin_username" name="direct_admin_username"
                                x-model="daUsername"
                                placeholder="e.g., janedoe01"
                                pattern="^[a-z][a-z0-9]*$"
                                minlength="3"
                                maxlength="16"
                                autocomplete="off"
                                class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white font-mono"
                                :required="isSharedHosting()">
                            <button type="button" @click="suggestUsername()" :disabled="generatingUsername"
                                class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700 transition disabled:opacity-50 whitespace-nowrap">
                                <span x-show="!generatingUsername">Suggest</span>
                                <span x-show="generatingUsername">…</span>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">3–16 characters, must start with a lowercase letter, only a–z and 0–9.</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="direct_admin_password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <input :type="password_visible ? 'text' : 'password'"
                                    id="direct_admin_password"
                                    name="direct_admin_password"
                                    x-model="daPassword"
                                    minlength="8"
                                    maxlength="64"
                                    autocomplete="new-password"
                                    placeholder="At least 8 characters"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white pr-20 font-mono"
                                    :required="isSharedHosting()">
                                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-1">
                                    <button type="button" @click="copyPassword()" class="p-1 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" title="Copy password">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </button>
                                    <button type="button" @click="password_visible = !password_visible" class="p-1 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" :title="password_visible ? 'Hide password' : 'Show password'">
                                        <svg x-show="!password_visible" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                        <svg x-show="password_visible" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <button type="button" @click="generatePassword()" :disabled="generatingPassword"
                                class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition disabled:opacity-50 whitespace-nowrap">
                                <span x-show="!generatingPassword">Generate</span>
                                <span x-show="generatingPassword">…</span>
                            </button>
                        </div>
                        <p x-show="passwordCopied" x-transition class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Copied to clipboard.</p>
                    </div>

                    <!-- Primary Domain -->
                    <div>
                        <label for="direct_admin_domain" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Primary Domain <span class="text-red-500">*</span></label>
                        <input type="text" id="direct_admin_domain" name="direct_admin_domain"
                            x-model="daDomain"
                            @blur="daDomain = (daDomain || '').toLowerCase()"
                            placeholder="e.g., example.com"
                            pattern="^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"
                            maxlength="253"
                            autocapitalize="off"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white font-mono"
                            :required="isSharedHosting()">
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The primary website domain that will be associated with this hosting account.</p>
                    </div>
                </div>
            </div>
            </template>

            <!-- Generic Credentials (Non-Shared Hosting) -->
            <template x-if="!isSharedHosting() && selectedProduct">
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Service Credentials (Optional)</h3>
                <div class="space-y-4">
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Username</label>
                        <input type="text" id="username" name="username" placeholder="e.g., admin" autocomplete="off" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password</label>
                        <div class="relative">
                            <input :type="password_visible ? 'text' : 'password'" id="password" name="password" autocomplete="new-password" placeholder="e.g., Secure@123" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white pr-10">
                            <button type="button" @click="password_visible = !password_visible" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                <svg x-show="!password_visible" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="password_visible" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="ip_address" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">IP Address</label>
                        <input type="text" id="ip_address" name="ip_address" placeholder="e.g., 192.168.1.1" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    </div>
                </div>
            </div>
            </template>

            <!-- Billing Dates -->
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="commenced_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Service Commenced On</label>
                    <input type="date" id="commenced_at" name="commenced_at" x-model="commencedAt" :max="todayIso()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">When the service actually started (for backdating). Leave blank if it starts today.</p>
                </div>

                <div>
                    <label for="next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date <span class="text-red-500">*</span></label>
                    <input type="date" id="next_due_date" name="next_due_date" x-model="nextDueDate" :min="commencedAt || null" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">When the next invoice is due. Invoices are generated 10 days prior.</p>
                </div>

                <div class="md:col-span-2">
                    <label for="suspend_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Suspension Date (Optional)</label>
                    <input type="date" id="suspend_date" name="suspend_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white"></textarea>
            </div>

            <!-- Generate Invoice -->
            <div class="flex items-center gap-3">
                <input type="checkbox" id="generate_invoice" name="generate_invoice" value="1" class="w-4 h-4 border-slate-300 rounded">
                <label for="generate_invoice" class="text-sm text-slate-900 dark:text-white">Generate invoice for this service (10 days before next due date)</label>
            </div>

            <!-- Hidden fields for DirectAdmin server + package -->
            <input type="hidden" name="node_id" :value="selectedNodeId">
            <input type="hidden" name="da_package_key" :value="selectedPackage?.package_key || ''">

            <!-- Submit Button -->
            <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                <button type="button" @click="addServiceModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                    Add Service
                </button>
            </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Package Picker Modal (overlays Add Service modal) -->
    <div x-show="packagePickerOpen && isSharedHosting()" x-transition class="fixed inset-0 bg-black/70 z-60 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-3xl w-full max-h-screen overflow-y-auto">
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Select a Hosting Package</h3>
                <button @click="packagePickerOpen = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                <template x-if="loadingPackages">
                    <div class="text-center py-8 text-slate-500 dark:text-slate-400">Loading packages...</div>
                </template>

                <template x-if="!loadingPackages && nodePackages.length === 0">
                    <div class="text-center py-8 text-slate-500 dark:text-slate-400">No packages available on this server.</div>
                </template>

                <template x-if="!loadingPackages && nodePackages.length > 0">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="pkg in nodePackages" :key="pkg.id">
                            <button type="button" @click="selectPackage(pkg)" class="p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg hover:border-blue-500 dark:hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition text-left">
                                <h4 class="font-semibold text-slate-900 dark:text-white" x-text="pkg.name"></h4>
                                <div class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-400">
                                    <p>💾 <span x-text="pkg.disk_quota + ' GB'"></span> disk</p>
                                    <p>🚀 <span x-text="pkg.bandwidth_quota + ' GB'"></span> bandwidth</p>
                                    <p>🌐 <span x-text="pkg.num_domains"></span> domains</p>
                                    <p>📧 <span x-text="pkg.num_email_accounts"></span> email accounts</p>
                                    <p>🗂️ <span x-text="pkg.num_databases"></span> databases</p>
                                </div>
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Add Domain Modal -->
    <div
        x-show="addDomainModal"
        x-cloak
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-domain-modal-title"
        @keydown.escape.window="addDomainModal = false"
    >
        <div class="fixed inset-0 bg-black/50" @click="addDomainModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div
                x-show="addDomainModal"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:scale-95"
                class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 max-h-[min(90vh,56rem)] flex flex-col"
                @click.stop
            >
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-800 shrink-0">
                    <div>
                        <h2 id="add-domain-modal-title" class="text-xl font-bold text-slate-900 dark:text-white">Add Domain</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Manually attach a domain to {{ $customer->name }}</p>
                    </div>
                    <button type="button" @click="addDomainModal = false" class="p-2 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition" aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form id="add-domain-form" method="POST" action="{{ route('admin.customers.add-domain', $customer) }}" class="flex-1 overflow-y-auto p-6 space-y-5">
                    @csrf

                    <div>
                        <label for="domain_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Domain name <span class="text-red-500">*</span></label>
                        <input type="text" id="domain_name" name="domain_name" placeholder="e.g., example.co.ke" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div>
                        <label for="domain_status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status <span class="text-red-500">*</span></label>
                        <select id="domain_status" name="status" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500" required>
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="expired">Expired</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="registered_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Registration date</label>
                            <input type="date" id="registered_at" name="registered_at" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="expires_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Expiration date <span class="text-red-500">*</span></label>
                            <input type="date" id="expires_at" name="expires_at" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500" required>
                        </div>
                    </div>

                    <div>
                        <label for="next_due_date_domain" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next invoice date <span class="text-slate-400 font-normal">(optional)</span></label>
                        <input type="date" id="next_due_date_domain" name="next_due_date" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">Leave blank to skip invoice generation</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="nameserver_1" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 1</label>
                            <input type="text" id="nameserver_1" name="nameserver_1" placeholder="ns1.example.com" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="nameserver_2" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 2</label>
                            <input type="text" id="nameserver_2" name="nameserver_2" placeholder="ns2.example.com" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="auto_renew" name="auto_renew" value="1" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <label for="auto_renew" class="text-sm text-slate-900 dark:text-white">Auto-renew domain</label>
                    </div>

                    <div>
                        <label for="domain_notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                        <textarea id="domain_notes" name="notes" rows="3" class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                </form>

                <div class="flex flex-col-reverse sm:flex-row gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 rounded-b-2xl shrink-0">
                    <button type="button" @click="addDomainModal = false" class="flex-1 px-4 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-white dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit" form="add-domain-form" class="flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Add Domain
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Invoice Modal -->
    <div
        x-show="createInvoiceModal"
        x-cloak
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        @keydown.escape.window="createInvoiceModal = false"
    >
        <div class="fixed inset-0 bg-black/50" @click="createInvoiceModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div
                x-show="createInvoiceModal"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:scale-95"
                class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 max-h-[min(90vh,56rem)] flex flex-col"
                @click.stop
            >
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-800 shrink-0">
                    <div class="pr-4">
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Create Invoice for {{ $customer->name }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Line item amounts in {{ $customerCurrencySymbol }} ({{ $customerCurrencyCode }})</p>
                    </div>
                    <button type="button" @click="createInvoiceModal = false" class="shrink-0 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.customers.create-invoice', $customer) }}" class="p-6 space-y-6 overflow-y-auto flex-1 min-h-0">
                @csrf

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                    <select name="status" required class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <option value="unpaid">Send Invoice (Unpaid)</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>

                <!-- Due Date -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Due Date</label>
                    <input type="date" name="due_date" value="{{ now()->addDays(7)->format('Y-m-d') }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>

                <!-- Tax Rate -->
                @if($customer->reseller_id || $customer->is_reseller)
                    <input type="hidden" name="tax_rate" value="0">
                    <p class="text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3">
                        Platform tax does not apply to this account. Invoice line amounts are tax-exempt.
                    </p>
                @else
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tax Rate (%)</label>
                        <input type="number" name="tax_rate" x-model="taxRate" min="0" max="100" step="0.01" value="0" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                    </div>
                @endif

                <!-- Line Items Header -->
                <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Line Items</h3>

                    <!-- Line Items List -->
                    <template x-for="(item, index) in invoiceItems" :key="index">
                        <div class="grid grid-cols-12 gap-2 mb-3">
                            <input
                                type="text"
                                :name="`items[${index}][description]`"
                                x-model="item.description"
                                placeholder="Product or service name"
                                required
                                class="col-span-6 px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm"
                            />
                            <input
                                type="number"
                                :name="`items[${index}][quantity]`"
                                x-model="item.quantity"
                                min="0.01"
                                step="0.01"
                                required
                                class="col-span-2 px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm"
                            />
                            <div class="col-span-3 relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-medium">{{ $customerCurrencySymbol }}</span>
                                <input
                                    type="number"
                                    :name="`items[${index}][unit_price]`"
                                    x-model="item.unit_price"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    required
                                    class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm"
                                />
                            </div>
                            <button
                                type="button"
                                @click="removeInvoiceItem(index)"
                                x-show="invoiceItems.length > 1"
                                class="col-span-1 px-2 py-2.5 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 font-medium text-sm transition"
                            >
                                ×
                            </button>
                        </div>
                    </template>

                    <!-- Add Line Item Button -->
                    <button
                        type="button"
                        @click="addInvoiceItem()"
                        class="mt-4 w-full px-4 py-2.5 rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:border-blue-400 dark:hover:border-blue-500 hover:text-blue-600 dark:hover:text-blue-400 font-medium transition text-sm"
                    >
                        + Add Line Item
                    </button>
                </div>

                <!-- Totals -->
                <div class="border-t border-slate-200 dark:border-slate-700 pt-4 space-y-2 text-right">
                    <div class="text-slate-700 dark:text-slate-300">
                        Subtotal: <span class="font-semibold" x-text="fmt(invoiceSubtotal())"></span>
                    </div>
                    <div x-show="taxRate > 0" class="text-slate-700 dark:text-slate-300">
                        Tax (<span x-text="taxRate"></span>%): <span class="font-semibold" x-text="fmt(invoiceTax())"></span>
                    </div>
                    <div class="text-lg font-bold text-slate-900 dark:text-white pt-2 border-t border-slate-200 dark:border-slate-700">
                        Total: <span x-text="fmt(invoiceTotal())"></span>
                    </div>
                </div>

                <!-- Notes -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes (Optional)</label>
                    <textarea name="notes" rows="3" placeholder="Add any additional notes to the invoice..." class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white"></textarea>
                </div>

                <!-- Submit Buttons -->
                <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" @click="createInvoiceModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Create Invoice
                    </button>
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

                <form :action="'/admin/services/' + editServiceId" method="POST" class="p-6 space-y-5 overflow-y-auto flex-1 min-h-0">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="return_to" value="customer">

                    <div>
                        <label for="edit_product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package / product</label>
                        <select id="edit_product_id" name="product_id" x-model="editProductId" required
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <template x-for="product in editServiceProducts()" :key="product.id">
                                <option :value="String(product.id)" x-text="productLabel(product)"></option>
                            </template>
                        </select>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Billing and dates only. To change a shared hosting plan on DirectAdmin, open the service and use <strong>Upgrade Hosting</strong>.</p>
                    </div>

                    <div>
                        <label for="edit_billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing cycle</label>
                        <select id="edit_billing_cycle" name="billing_cycle" x-model="editBillingCycle" required
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-annual</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div>
                        <label for="edit_custom_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Custom price ({{ $customerCurrencyCode }})</label>
                        <input type="number" id="edit_custom_price" name="custom_price" step="0.01" min="0" x-model="editCustomPrice"
                               class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Leave empty to use product price">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_commenced_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Commenced</label>
                            <input type="date" id="edit_commenced_at" name="commenced_at" x-model="editCommencedAt"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="edit_next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next due date</label>
                            <input type="date" id="edit_next_due_date" name="next_due_date" x-model="editNextDueDate" required
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="editServiceModal = false"
                                class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                            Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                            Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Service Confirmation Modal -->
    <div x-show="cancelServiceModal"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/50 z-50 flex items-end"
         @click.self="cancelServiceModal = false"
         style="display: none;">
        <div class="w-full bg-white dark:bg-slate-900 rounded-t-2xl shadow-2xl p-6 space-y-5"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-full"
             x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-y-0"
             x-transition:leave-end="translate-y-full">

            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-red-600 dark:text-red-400">Cancel Service</h3>
                <button @click="cancelServiceModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-4 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/40">
                <p class="text-sm text-red-700 dark:text-red-300">
                    Are you sure you want to cancel <strong x-text="cancelServiceName"></strong>?
                </p>
                <ul class="mt-2 text-xs text-red-600 dark:text-red-400 space-y-1 list-disc list-inside">
                    <li>The service will be marked as cancelled immediately</li>
                    <li>Any linked unpaid invoices will also be cancelled</li>
                    <li>No further charges will be generated for this service</li>
                    <li>This action cannot be undone</li>
                </ul>
            </div>

            <div class="flex gap-3">
                <button @click="cancelServiceModal = false"
                    class="flex-1 px-4 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 text-sm font-medium transition-colors">
                    Keep Service
                </button>
                <form :action="'/admin/services/' + cancelServiceId + '/cancel'" method="POST" class="flex-1">
                    @csrf
                    <button type="submit"
                        class="w-full px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition-colors">
                        Cancel Service
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function initCustomerData() {
    return {
        tab: @js(request('tab', 'overview')),
        addServiceModal: false,
        editServiceModal: false,
        editServiceId: null,
        editServiceName: '',
        editProductId: '',
        editBillingCycle: 'monthly',
        editCustomPrice: '',
        editCommencedAt: '',
        editNextDueDate: '',
        editProductType: '',
        services: @json($servicesForJs),
        addDomainModal: false,
        createInvoiceModal: false,
        cancelServiceModal: false,
        cancelServiceId: null,
        cancelServiceName: '',
        productName: '',
        password_visible: false,
        selectedProductType: '',
        selectedProduct: '',
        products: @json($productsForJs),
        customerCurrency: @json($customerCurrencyCode),
        customerCurrencySymbol: @json($customerCurrencySymbol),
        exchangeRate: {{ json_encode($customerExchangeRate) }},
        billingCycle: 'monthly',
        customPrice: @json(old('custom_price', '')),

        // DirectAdmin (shared hosting) form state
        daUsername: '',
        daPassword: '',
        daDomain: '',
        generatingPassword: false,
        generatingUsername: false,
        passwordCopied: false,

        // DirectAdmin server + package selection
        daNodes: @json($daNodes),
        selectedNodeId: '',
        selectedPackageId: '',
        selectedPackage: null,
        nodePackages: [],
        loadingPackages: false,
        packagePickerOpen: false,

        // Date fields
        commencedAt: '',
        nextDueDate: '',

        // Custom invoice modal
        invoiceItems: [{ description: '', quantity: 1, unit_price: '' }],
        taxRate: 0,

        currentProduct() {
            if (!this.selectedProduct) return null;
            return this.products.find(p => p.id == this.selectedProduct) || null;
        },

        isSharedHosting() {
            return this.currentProduct()?.type === 'shared_hosting';
        },

        getProductTypes() {
            const types = new Set(this.products.map(p => p.type));
            return Array.from(types).sort();
        },

        getProductsByType() {
            if (!this.selectedProductType) return [];
            return this.products.filter(p => p.type === this.selectedProductType);
        },

        openEditService(serviceId) {
            const service = this.services.find(s => s.id === serviceId);
            if (!service) return;

            this.editServiceId = service.id;
            this.editServiceName = service.name;
            this.editProductId = String(service.product_id);
            this.editProductType = service.product_type || '';
            this.editBillingCycle = service.billing_cycle || 'monthly';
            this.editCustomPrice = service.custom_price ?? '';
            this.editCommencedAt = service.commenced_at || '';
            this.editNextDueDate = service.next_due_date || '';
            this.editServiceModal = true;
        },

        editServiceProducts() {
            if (!this.editProductType) return this.products;
            return this.products.filter(p => p.type === this.editProductType);
        },

        productLabel(product) {
            const price = product.monthly_price
                ? ` — ${this.customerCurrencySymbol} ${Number(product.monthly_price).toLocaleString()}/mo`
                : '';
            return `${product.name}${price}`;
        },

        onProductTypeChange() {
            console.log('[onProductTypeChange] Changing product type, clearing selections');
            this.selectedProduct = '';
            this.selectedNodeId = '';
            this.selectedPackage = null;
            this.selectedPackageId = '';
            this.nodePackages = [];
            this.onProductChange();
        },

        onProductChange() {
            const product = this.currentProduct();
            this.productName = product?.name || '';
            this.customPrice = '';

            // Reset only DA credentials when switching products
            // Keep server/package selection intact
            this.daUsername = '';
            this.daPassword = '';
            this.daDomain = '';

            if (this.isSharedHosting()) {
                this.suggestUsername();
            }
        },

        catalogPriceKes() {
            const product = this.currentProduct();
            if (!product) return 0;

            const monthly = parseFloat(product.monthly_price) || 0;
            const yearly = parseFloat(product.yearly_price) || (monthly * 12);

            switch (this.billingCycle) {
                case 'quarterly': return monthly * 3;
                case 'semi-annual': return monthly * 6;
                case 'annual': return yearly;
                default: return monthly;
            }
        },

        catalogPriceDisplay() {
            return this.convertKesToDisplay(this.catalogPriceKes());
        },

        convertKesToDisplay(kes) {
            if (this.customerCurrency === 'KES') {
                return parseFloat(kes) || 0;
            }

            return (parseFloat(kes) || 0) * (parseFloat(this.exchangeRate) || 1);
        },

        billingCycleLabel() {
            return ({
                monthly: 'month',
                quarterly: 'quarter',
                'semi-annual': '6 months',
                annual: 'year',
            })[this.billingCycle] || this.billingCycle;
        },

        formatCustomerMoney(amount) {
            const decimals = this.customerCurrency === 'KES' ? 0 : 2;
            const formatted = parseFloat(amount || 0).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

            return `${this.customerCurrencySymbol} ${formatted}`;
        },

        async suggestUsername() {
            if (this.generatingUsername) return;
            this.generatingUsername = true;

            try {
                const res = await fetch('{{ route('admin.customers.generate-username', $customer) }}', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('Failed to suggest username');
                const data = await res.json();
                this.daUsername = data.username || '';
            } catch (e) {
                console.error(e);
            } finally {
                this.generatingUsername = false;
            }
        },

        async generatePassword() {
            if (this.generatingPassword) return;
            this.generatingPassword = true;

            try {
                const res = await fetch('{{ route('admin.customers.generate-password') }}?length=16', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('Failed to generate password');
                const data = await res.json();
                this.daPassword = data.password || '';
                this.password_visible = true;
            } catch (e) {
                console.error(e);
            } finally {
                this.generatingPassword = false;
            }
        },

        async copyPassword() {
            if (!this.daPassword) return;
            try {
                await navigator.clipboard.writeText(this.daPassword);
                this.passwordCopied = true;
                setTimeout(() => { this.passwordCopied = false; }, 2000);
            } catch (e) {
                console.error('Clipboard copy failed', e);
            }
        },

        async onNodeChange() {
            console.log('[onNodeChange] selectedNodeId:', this.selectedNodeId);

            if (!this.selectedNodeId) {
                console.log('[onNodeChange] No node selected, clearing packages');
                this.nodePackages = [];
                this.selectedPackage = null;
                this.selectedPackageId = '';
                return;
            }

            this.loadingPackages = true;
            const url = `/admin/nodes/${this.selectedNodeId}/packages-json`;
            console.log('[onNodeChange] Fetching packages from:', url);

            try {
                const res = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                console.log('[onNodeChange] Response status:', res.status);
                console.log('[onNodeChange] Response headers:', {
                    contentType: res.headers.get('content-type'),
                    contentLength: res.headers.get('content-length'),
                });

                if (!res.ok) {
                    const errorText = await res.text();
                    console.error('[onNodeChange] HTTP Error response:', errorText);
                    throw new Error(`HTTP ${res.status}: ${errorText.substring(0, 200)}`);
                }

                const jsonData = await res.json();
                console.log('[onNodeChange] Raw JSON response:', jsonData);

                this.nodePackages = jsonData;
                console.log('[onNodeChange] ✅ Packages loaded:', this.nodePackages.length, 'items');
                console.log('[onNodeChange] State nodePackages is now:', this.nodePackages);

                if (this.nodePackages.length === 0) {
                    console.warn('[onNodeChange] ⚠️ Node has 0 packages! This node may not have any packages synced yet.');
                } else {
                    console.log('[onNodeChange] Package names:', this.nodePackages.map(p => `${p.name} (disk: ${p.disk_quota}GB, id: ${p.id})`));
                }

                this.selectedPackage = null;
                this.selectedPackageId = '';
                console.log('[onNodeChange] State after reset:', { nodePackages: this.nodePackages.length, selectedPackageId: this.selectedPackageId });
            } catch (e) {
                console.error('[onNodeChange] ❌ Error fetching packages:', e.message);
                console.error('[onNodeChange] Full error:', e);
                this.nodePackages = [];
                alert(`Error loading packages: ${e.message}`);
            } finally {
                this.loadingPackages = false;
            }
        },

        onPackageChange() {
            console.log('[onPackageChange] selectedPackageId:', this.selectedPackageId);
            console.log('[onPackageChange] nodePackages:', this.nodePackages);

            if (!this.selectedPackageId) {
                this.selectedPackage = null;
                return;
            }
            const pkg = this.nodePackages.find(p => p.id == this.selectedPackageId);
            console.log('[onPackageChange] Found package:', pkg);
            this.selectedPackage = pkg || null;
        },

        onAddServiceSubmit(e) {
            console.log('[onAddServiceSubmit] Form submission', {
                isSharedHosting: this.isSharedHosting(),
                selectedNodeId: this.selectedNodeId,
                selectedPackage: this.selectedPackage,
                daUsername: this.daUsername,
                daPassword: this.daPassword ? '***' : '',
                daDomain: this.daDomain,
            });

            // Extra client-side guard: shared hosting needs all DA fields + server + package.
            if (this.isSharedHosting()) {
                if (!this.selectedNodeId) {
                    console.error('[onAddServiceSubmit] Missing selectedNodeId');
                    e.preventDefault();
                    alert('Please select a DirectAdmin server.');
                    return false;
                }
                if (!this.selectedPackage) {
                    console.error('[onAddServiceSubmit] Missing selectedPackage');
                    e.preventDefault();
                    alert('Please select a hosting package from the server.');
                    return false;
                }
                if (!this.daUsername || !this.daPassword || !this.daDomain) {
                    console.error('[onAddServiceSubmit] Missing DA credentials');
                    e.preventDefault();
                    alert('DirectAdmin username, password, and primary domain are required for shared hosting.');
                    return false;
                }
            }
            console.log('[onAddServiceSubmit] Validation passed, submitting form');
        },

        todayIso() {
            return new Date().toISOString().slice(0, 10);
        },

        addInvoiceItem() {
            this.invoiceItems.push({ description: '', quantity: 1, unit_price: '' });
        },
        removeInvoiceItem(i) {
            if (this.invoiceItems.length > 1) this.invoiceItems.splice(i, 1);
        },
        invoiceSubtotal() {
            return this.invoiceItems.reduce((s, it) => s + (parseFloat(it.quantity||0) * parseFloat(it.unit_price||0)), 0);
        },
        invoiceTax() {
            return this.invoiceSubtotal() * (parseFloat(this.taxRate||0) / 100);
        },
        invoiceTotal() {
            return this.invoiceSubtotal() + this.invoiceTax();
        },
        fmt(v) {
            return this.formatCustomerMoney(v);
        },

        init() {
            const self = this;
            console.log('[init] Customer data initialized');
            console.log('[init] Available DA Nodes:', {
                count: self.daNodes.length,
                nodes: self.daNodes.map(n => ({ id: n.id, name: n.name, hostname: n.hostname, status: n.status }))
            });
            console.log('[init] Available Products:', {
                count: self.products.length,
                sharedHosting: self.products.filter(p => p.type === 'shared_hosting').length
            });

            // Watch for modal open/close
            this.$watch('addServiceModal', (val) => {
                console.log('[watch:addServiceModal] Modal is now', val ? 'OPEN' : 'CLOSED');
                if (val) {
                    console.log('[watch:addServiceModal] Current state:', {
                        selectedProductType: self.selectedProductType,
                        selectedProduct: self.selectedProduct,
                        selectedNodeId: self.selectedNodeId,
                        nodePackages: self.nodePackages.length,
                        daNodes: self.daNodes.length,
                    });
                }
            });

            // Watch for nodePackages changes
            this.$watch('nodePackages', (val) => {
                console.log('[watch:nodePackages] Packages changed to', val.length, 'items');
                if (val.length > 0) {
                    console.log('[watch:nodePackages] Packages:', val.map(p => ({ id: p.id, name: p.name, key: p.package_key })));
                }
            });

            // Watch for selectedNodeId changes
            this.$watch('selectedNodeId', (val) => {
                console.log('[watch:selectedNodeId] Selected node changed to:', val);
                const node = self.daNodes.find(n => n.id == val);
                if (node) {
                    console.log('[watch:selectedNodeId] Node details:', { id: node.id, name: node.name, hostname: node.hostname });
                }
            });

            // Watch for selectedPackage changes
            this.$watch('selectedPackage', (val) => {
                console.log('[watch:selectedPackage] Selected package changed to:', val?.name || 'none');
            });

            // Watch for selectedProduct changes
            this.$watch('selectedProduct', (val) => {
                console.log('[watch:selectedProduct] Selected product changed to:', val);
            });
        }
    }
}
</script>
@endsection
