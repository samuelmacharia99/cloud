@extends('layouts.admin')

@section('title', 'Reseller: ' . $user->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.resellers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">{{ $user->name }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-2xl">
                    {{ substr($user->name, 0, 1) }}
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $user->name }}</h1>
                    <p class="text-slate-600 dark:text-slate-400 mt-1">{{ $user->email }}</p>
                    @if ($user->company_name)
                        <p class="text-slate-600 dark:text-slate-400 mt-1 font-medium">{{ $user->company_name }}</p>
                    @endif
                </div>
            </div>

            <!-- Status & Actions -->
            <div class="text-right space-y-2">
                <div>
                    <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">
                        Active Reseller
                    </span>
                </div>
                <form action="{{ route('admin.resellers.demote', $user) }}" method="POST" class="inline">
                    @csrf
                    <x-confirmation-dialog
                        title="Demote Reseller?"
                        message="This user will no longer have reseller privileges."
                        confirmText="Demote"
                        danger
                        :action="route('admin.resellers.demote', $user)"
                    >
                        <button type="button" class="px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition text-sm font-medium">
                            Remove Reseller Status
                        </button>
                    </x-confirmation-dialog>
                </form>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Services Managed</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $services->count() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Customers Served</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $customerIds->count() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Package</p>
            <p class="text-lg font-bold text-slate-900 dark:text-white mt-2">{{ $user->resellerPackage?->name ?? '—' }}</p>
            @if($user->resellerPackage)
                <p class="text-xs text-slate-500 mt-1">Ksh {{ number_format($user->resellerPackage->price, 0) }}/{{ $user->resellerPackage->billing_cycle === 'monthly' ? 'mo' : 'yr' }}</p>
            @endif
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Member Since</p>
            <p class="text-lg font-bold text-slate-900 dark:text-white mt-2">{{ $user->created_at->format('M d, Y') }}</p>
        </div>
    </div>

    <!-- Tabbed Content -->
    <div x-data="{ activeTab: 'overview', addDomainModal: false, addServiceModal: false, upgradeModal: false, editBillingModal: false }"
         x-init="@if($errors->any() && old('_form') === 'add_service') addServiceModal = true; activeTab = 'services' @elseif($errors->any()) addDomainModal = true; activeTab = 'domains' @endif"
         class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 dark:border-slate-800">
            <div class="flex gap-1 px-6">
                <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Overview
                </button>
                <button @click="activeTab = 'services'" :class="activeTab === 'services' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Services ({{ $services->count() }})
                </button>
                <button @click="activeTab = 'customers'" :class="activeTab === 'customers' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Customers ({{ $customerIds->count() }})
                </button>
                <button @click="activeTab = 'domains'" :class="activeTab === 'domains' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Domains ({{ $domains->count() }})
                </button>
                <button @click="activeTab = 'invoices'" :class="activeTab === 'invoices' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Invoices ({{ $resellerInvoices->count() }})
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Overview Tab -->
            <div x-show="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Contact Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Contact Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Email</p>
                                <p class="text-slate-900 dark:text-white font-medium">{{ $user->email }}</p>
                            </div>
                            @if ($user->phone)
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Phone</p>
                                    <p class="text-slate-900 dark:text-white font-medium">{{ $user->phone }}</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Reseller Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Reseller Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Status</p>
                                <p class="text-slate-900 dark:text-white font-medium">Active</p>
                            </div>
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Current Package</p>
                                @if ($user->resellerPackage)
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="font-medium text-slate-900 dark:text-white">{{ $user->resellerPackage->name }}</span>
                                        <span class="px-2 py-0.5 bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 rounded text-xs">
                                            {{ ucfirst($user->resellerPackage->billing_cycle) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
                                        Subscribed {{ $user->package_subscribed_at?->diffForHumans() }}
                                        &bull;
                                        {{ $user->getManagedActiveServicesCount() }} / {{ $user->resellerPackage->storage_space }} service slots
                                        &bull;
                                        {{ $customerIds->count() }} / {{ $user->resellerPackage->max_users }} customers
                                    </p>
                                @else
                                    <span class="inline-block px-2 py-1 bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 rounded text-xs font-medium mb-3">No package assigned</span>
                                @endif

                                <!-- Assign / Change Package Form -->
                                <form method="POST" action="{{ route('admin.resellers.assign-package', $user) }}" class="flex items-center gap-2">
                                    @csrf
                                    <select name="reseller_package_id" class="flex-1 px-3 py-1.5 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 text-slate-900 dark:text-white @error('reseller_package_id') border-red-500 @enderror">
                                        @foreach ($packages->groupBy('billing_cycle') as $cycle => $cyclePackages)
                                            <optgroup label="{{ ucfirst($cycle) }}">
                                                @foreach ($cyclePackages as $package)
                                                    <option value="{{ $package->id }}" {{ $user->reseller_package_id == $package->id ? 'selected' : '' }}>
                                                        {{ $package->name }} — Ksh {{ number_format($package->price, 0) }}/{{ $cycle === 'monthly' ? 'mo' : 'yr' }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">
                                        {{ $user->resellerPackage ? 'Change' : 'Assign' }}
                                    </button>
                                </form>
                            </div>
                            @if ($user->company)
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Company</p>
                                    <p class="text-slate-900 dark:text-white font-medium">{{ $user->company }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Package Details Card -->
                @if ($user->resellerPackage)
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-50/50 dark:from-blue-950/20 dark:to-blue-950/10 border border-blue-200 dark:border-blue-900/30 rounded-lg p-6">
                        <div class="flex items-start justify-between mb-6">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Package Details</h3>
                            <div class="flex items-center gap-2">
                                <button @click="editBillingModal = true" class="p-1.5 text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 rounded transition" title="Edit billing dates">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <button @click="upgradeModal = true" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition inline-flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    <span>Upgrade Plan</span>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase mb-1">Package</p>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $user->resellerPackage->name }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase mb-1">Price</p>
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Ksh {{ number_format($user->resellerPackage->price, 0) }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ ucfirst($user->resellerPackage->billing_cycle) }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase mb-1">Next Invoice</p>
                                @php
                                    $nextInvoiceDate = $user->next_invoice_date;
                                    $daysUntilInvoice = $nextInvoiceDate ? (int) $nextInvoiceDate->diffInDays(now()) : null;
                                @endphp
                                @if ($nextInvoiceDate && $daysUntilInvoice !== null)
                                    <p class="text-sm font-semibold {{ $daysUntilInvoice <= 3 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                                        {{ $nextInvoiceDate->format('M d, Y') }}
                                    </p>
                                    @if ($daysUntilInvoice > 0 && $daysUntilInvoice <= 3)
                                        <p class="text-xs text-amber-600 dark:text-amber-400">in {{ $daysUntilInvoice }} days</p>
                                    @elseif ($daysUntilInvoice <= 0)
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ abs($daysUntilInvoice) }} days overdue</p>
                                    @endif
                                @else
                                    <p class="text-sm text-slate-500">—</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase mb-1">Suspension Date</p>
                                @php
                                    $suspendDate = $user->package_suspend_date;
                                    $isPast = $suspendDate && $suspendDate->isPast();
                                @endphp
                                @if ($suspendDate)
                                    <p class="text-sm font-semibold {{ $isPast ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">
                                        {{ $suspendDate->format('M d, Y') }}
                                    </p>
                                    @if ($isPast)
                                        <p class="text-xs text-red-600 dark:text-red-400">OVERDUE</p>
                                    @endif
                                @else
                                    <p class="text-sm text-slate-500">—</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Services Tab -->
            <div x-show="activeTab === 'services'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Services ({{ $services->count() }})</h3>
                    <button @click="addServiceModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                        + Add Service
                    </button>
                </div>

                @if ($services->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Service</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Owner</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Product</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Next Renewal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($services as $service)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                {{ $service->name }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $service->user->name }}
                                            @if ($service->user_id === $user->id)
                                                <span class="ml-1 text-xs text-purple-600 dark:text-purple-400">(reseller)</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $service->product->name }}
                                        </td>
                                        <td class="py-3 px-4">
                                            <x-status-badge :status="$service->status" type="service" />
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            @if ($service->next_due_date)
                                                {{ $service->next_due_date->format('M d, Y') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No services managed yet</p>
                    </div>
                @endif
            </div>

            <!-- Customers Tab -->
            <div x-show="activeTab === 'customers'">
                @if ($customerIds->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Email</th>
                                    <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white">Services</th>
                                    <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Total Spend</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($customers as $customer)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('admin.customers.show', $customer) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                {{ $customer->name }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $customer->email }}
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="inline-block px-3 py-1 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 rounded-full text-xs font-medium">
                                                {{ $customer->services_count ?? 0 }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right font-medium text-slate-900 dark:text-white">
                                            <x-currency-formatter :amount="$customer->total_spent ?? 0" currency="KES" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No customers served yet</p>
                    </div>
                @endif
            </div>

            <!-- Domains Tab -->
            <div x-show="activeTab === 'domains'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Domains ({{ $domains->count() }})</h3>
                    <button @click="addDomainModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                        + Add Domain
                    </button>
                </div>

                <!-- Domains List -->
                @if ($domains->count() > 0)
                    <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800">
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Domain</th>
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Registered</th>
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Expires</th>
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Next Invoice</th>
                                        <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                                        <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                    @foreach ($domains as $domain)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <td class="py-3 px-4 font-medium text-slate-900 dark:text-white">
                                                {{ $domain->name }}{{ $domain->extension }}
                                            </td>
                                            <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                                <a href="{{ route('admin.customers.show', $domain->user) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                    {{ $domain->user->name }}
                                                </a>
                                            </td>
                                            <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                                {{ $domain->registered_at?->format('M d, Y') ?? '—' }}
                                            </td>
                                            <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                                {{ $domain->expires_at?->format('M d, Y') ?? '—' }}
                                            </td>
                                            <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                                {{ $domain->next_invoice_date?->format('M d, Y') ?? '—' }}
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="inline-block px-2.5 py-0.5 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                                                    {{ ucfirst($domain->status) }}
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <a href="{{ route('admin.domains.edit', $domain) }}"
                                                   class="inline-block px-3 py-1.5 text-xs font-medium bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 text-blue-700 dark:text-blue-300 rounded-lg transition">
                                                    Edit
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No domains added yet</p>
                    </div>
                @endif
            </div>

            <!-- Invoices Tab -->
            <div x-show="activeTab === 'invoices'">
                @if ($resellerInvoices->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Invoice #</th>
                                    <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Amount</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Due Date</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Paid Date</th>
                                    <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                @foreach ($resellerInvoices as $invoice)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4 font-medium text-slate-900 dark:text-white">
                                            {{ $invoice->invoice_number }}
                                        </td>
                                        <td class="py-3 px-4 text-right font-semibold text-slate-900 dark:text-white">
                                            <x-currency-formatter :amount="$invoice->total" currency="KES" />
                                        </td>
                                        <td class="py-3 px-4">
                                            @if ($invoice->status->value === 'paid')
                                                <span class="inline-block px-2.5 py-0.5 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                                                    Paid
                                                </span>
                                            @elseif ($invoice->status->value === 'unpaid')
                                                <span class="inline-block px-2.5 py-0.5 bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 rounded-full text-xs font-medium">
                                                    Unpaid
                                                </span>
                                            @elseif ($invoice->status->value === 'overdue')
                                                <span class="inline-block px-2.5 py-0.5 bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300 rounded-full text-xs font-medium">
                                                    Overdue
                                                </span>
                                            @else
                                                <span class="inline-block px-2.5 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-full text-xs font-medium">
                                                    {{ ucfirst($invoice->status->value) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $invoice->due_date?->format('M d, Y') ?? '—' }}
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            {{ $invoice->paid_date?->format('M d, Y') ?? '—' }}
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <a href="{{ route('admin.invoices.show', $invoice) }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No subscription invoices yet</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Add Service Modal -->
        <div x-show="addServiceModal" x-transition fixed inset-0 bg-black/50 z-50 flex items-end @click.self="addServiceModal = false">
            <div class="bg-white dark:bg-slate-900 w-full max-w-2xl mx-auto rounded-t-2xl shadow-2xl overflow-y-auto max-h-[90vh] flex flex-col">
                <!-- Sticky Header -->
                <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Add Server Service</h2>
                    <button @click="addServiceModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Form Content -->
                <form id="add-service-form" action="{{ route('admin.resellers.add-service', $user) }}" method="POST" class="flex-1 overflow-y-auto p-6">
                    @csrf
                    <input type="hidden" name="_form" value="add_service">
                    <div class="space-y-6">

                        <!-- Owner Selection -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Service Owner <span class="text-red-500">*</span></label>
                            <select name="owner_id" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('owner_id') border-red-500 @enderror">
                                <option value="">Select owner (reseller or customer)</option>
                                @foreach ($ownerOptions as $option)
                                    <option value="{{ $option['id'] }}" {{ old('owner_id') == $option['id'] ? 'selected' : '' }}>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('owner_id')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Product -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Product <span class="text-red-500">*</span></label>
                            <select name="product_id" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('product_id') border-red-500 @enderror">
                                <option value="">Select product</option>
                                @foreach ($serverProducts->groupBy('type') as $type => $products)
                                    <optgroup label="{{ $type === 'vps' ? 'VPS' : 'Dedicated Server' }}">
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" {{ old('product_id') == $product->id ? 'selected' : '' }}>
                                                {{ $product->name }}
                                                @if ($product->wholesale_monthly_price)
                                                    — KES {{ number_format($product->wholesale_monthly_price, 2) }}/mo (wholesale)
                                                @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('product_id')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Service Name -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Service Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" placeholder="e.g., Production VPS #1" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror" value="{{ old('name') }}">
                            @error('name')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Billing Cycle + Status -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing Cycle <span class="text-red-500">*</span></label>
                                <select name="billing_cycle" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('billing_cycle') border-red-500 @enderror">
                                    <option value="monthly"     {{ old('billing_cycle', 'monthly') === 'monthly'     ? 'selected' : '' }}>Monthly</option>
                                    <option value="quarterly"   {{ old('billing_cycle') === 'quarterly'   ? 'selected' : '' }}>Quarterly</option>
                                    <option value="semi-annual" {{ old('billing_cycle') === 'semi-annual' ? 'selected' : '' }}>Semi-Annual</option>
                                    <option value="annual"      {{ old('billing_cycle') === 'annual'      ? 'selected' : '' }}>Annual</option>
                                </select>
                                @error('billing_cycle')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status <span class="text-red-500">*</span></label>
                                <select name="status" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('status') border-red-500 @enderror">
                                    <option value="active"       {{ old('status', 'active') === 'active'       ? 'selected' : '' }}>Active</option>
                                    <option value="pending"      {{ old('status') === 'pending'      ? 'selected' : '' }}>Pending</option>
                                    <option value="provisioning" {{ old('status') === 'provisioning' ? 'selected' : '' }}>Provisioning</option>
                                    <option value="suspended"    {{ old('status') === 'suspended'    ? 'selected' : '' }}>Suspended</option>
                                    <option value="terminated"   {{ old('status') === 'terminated'   ? 'selected' : '' }}>Terminated</option>
                                    <option value="failed"       {{ old('status') === 'failed'       ? 'selected' : '' }}>Failed</option>
                                    <option value="cancelled"    {{ old('status') === 'cancelled'    ? 'selected' : '' }}>Cancelled</option>
                                </select>
                                @error('status')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Commenced Date</label>
                                <input type="date" name="commenced_at" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('commenced_at') border-red-500 @enderror" value="{{ old('commenced_at') }}">
                                @error('commenced_at')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Next Due Date <span class="text-red-500">*</span></label>
                                <input type="date" name="next_due_date" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('next_due_date') border-red-500 @enderror" value="{{ old('next_due_date') }}">
                                @error('next_due_date')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Server Credentials -->
                        <div>
                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Server Credentials <span class="text-slate-400 font-normal">(optional)</span></h4>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Username / Login</label>
                                        <input type="text" name="username" autocomplete="off" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('username') border-red-500 @enderror" value="{{ old('username') }}">
                                        @error('username')
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Password</label>
                                        <input type="text" name="password" autocomplete="new-password" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('password') border-red-500 @enderror" value="{{ old('password') }}">
                                        @error('password')
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">IP Address</label>
                                    <input type="text" name="ip_address" placeholder="e.g., 192.168.1.1" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('ip_address') border-red-500 @enderror" value="{{ old('ip_address') }}">
                                    @error('ip_address')
                                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                            <textarea name="notes" rows="3" placeholder="Internal notes about this service..." class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('notes') border-red-500 @enderror">{{ old('notes') }}</textarea>
                            @error('notes')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Generate Invoice -->
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700">
                            <input type="hidden" name="generate_invoice" value="0">
                            <input type="checkbox" name="generate_invoice" value="1" id="svc_generate_invoice" {{ old('generate_invoice') ? 'checked' : '' }} class="mt-0.5 w-4 h-4 border border-slate-300 rounded bg-white dark:bg-slate-800 focus:ring-2 focus:ring-blue-500 accent-blue-600 cursor-pointer">
                            <div>
                                <label for="svc_generate_invoice" class="text-sm font-medium text-slate-900 dark:text-white cursor-pointer">Generate invoice</label>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Creates an unpaid invoice at wholesale price and links it to this service.</p>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Sticky Footer -->
                <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-end gap-3">
                    <button @click="addServiceModal = false" class="px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition font-medium">
                        Cancel
                    </button>
                    <button form="add-service-form" type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Add Service
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Domain Modal -->
        <div x-show="addDomainModal" x-transition fixed inset-0 bg-black/50 z-50 flex items-end @click.self="addDomainModal = false">
            <div class="bg-white dark:bg-slate-900 w-full max-w-2xl mx-auto rounded-t-2xl shadow-2xl overflow-y-auto max-h-[90vh] flex flex-col">
                <!-- Sticky Header -->
                <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Add Domain</h2>
                    <button @click="addDomainModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Form Content -->
                <form id="add-domain-form" action="{{ route('admin.resellers.add-domain', $user) }}" method="POST" class="flex-1 overflow-y-auto p-6">
                    @csrf
                    <div class="space-y-6">
                        <!-- Owner Selection -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Owner</label>
                            <select name="owner_id" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('owner_id') border-red-500 @enderror">
                                <option value="">Select owner (reseller or customer)</option>
                                @foreach ($ownerOptions as $option)
                                    <option value="{{ $option['id'] }}" {{ old('owner_id') == $option['id'] ? 'selected' : '' }}>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('owner_id')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Domain Name -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
                            <input type="text" name="domain_name" placeholder="e.g., talksasa.co.ke" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('domain_name') border-red-500 @enderror" value="{{ old('domain_name') }}">
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Enter full domain e.g. talksasa.co.ke</p>
                            @error('domain_name')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Extension -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Extension</label>
                            <select name="extension" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('extension') border-red-500 @enderror">
                                <option value="">Select extension</option>
                                @foreach ($extensions as $ext)
                                    <option value="{{ $ext }}" {{ old('extension') == $ext ? 'selected' : '' }}>
                                        {{ $ext }}
                                    </option>
                                @endforeach
                            </select>
                            @error('extension')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                            <select name="status" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('status') border-red-500 @enderror">
                                <option value="active" {{ old('status', 'active') == 'active' ? 'selected' : '' }}>Active</option>
                                <option value="pending" {{ old('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="expired" {{ old('status') == 'expired' ? 'selected' : '' }}>Expired</option>
                                <option value="suspended" {{ old('status') == 'suspended' ? 'selected' : '' }}>Suspended</option>
                            </select>
                            @error('status')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Dates Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Registered Date -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registered Date</label>
                                <input type="date" name="registered_at" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('registered_at') border-red-500 @enderror" value="{{ old('registered_at') }}">
                                @error('registered_at')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Expiry Date -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Expiry Date <span class="text-red-500">*</span></label>
                                <input type="date" name="expires_at" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('expires_at') border-red-500 @enderror" value="{{ old('expires_at') }}">
                                @error('expires_at')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Next Invoice Date -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Next Invoice Date</label>
                            <input type="date" name="next_invoice_date" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('next_invoice_date') border-red-500 @enderror" value="{{ old('next_invoice_date') }}">
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Used for auto-invoice generation</p>
                            @error('next_invoice_date')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Nameservers Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Nameserver 1 -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 1</label>
                                <input type="text" name="nameserver_1" placeholder="e.g., ns1.example.com" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('nameserver_1') border-red-500 @enderror" value="{{ old('nameserver_1') }}">
                                @error('nameserver_1')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Nameserver 2 -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 2</label>
                                <input type="text" name="nameserver_2" placeholder="e.g., ns2.example.com" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('nameserver_2') border-red-500 @enderror" value="{{ old('nameserver_2') }}">
                                @error('nameserver_2')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Auto Renew -->
                        <div class="flex items-center gap-2">
                            <input type="hidden" name="auto_renew" value="0">
                            <input type="checkbox" name="auto_renew" value="1" id="auto_renew" {{ old('auto_renew', 1) ? 'checked' : '' }} class="w-4 h-4 border border-slate-300 rounded bg-white dark:bg-slate-800 focus:ring-2 focus:ring-blue-500 accent-blue-600 cursor-pointer">
                            <label for="auto_renew" class="text-sm font-medium text-slate-700 dark:text-slate-300 cursor-pointer">Enable auto-renewal</label>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                            <textarea name="notes" rows="3" placeholder="e.g., renewal reminder, special terms..." class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 @error('notes') border-red-500 @enderror">{{ old('notes') }}</textarea>
                            @error('notes')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </form>

                <!-- Sticky Footer -->
                <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-end gap-3">
                    <button @click="addDomainModal = false" class="px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition font-medium">
                        Cancel
                    </button>
                    <button form="add-domain-form" type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Add Domain
                    </button>
                </div>
            </div>
        </div>

        <!-- Upgrade Plan Modal -->
        <div x-show="upgradeModal" x-transition fixed inset-0 bg-black/50 z-50 flex items-end @click.self="upgradeModal = false">
            <div class="bg-white dark:bg-slate-900 w-full max-w-2xl mx-auto rounded-t-2xl shadow-2xl overflow-y-auto max-h-[90vh] flex flex-col">
                <!-- Sticky Header -->
                <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Upgrade Reseller Plan</h2>
                    <button @click="upgradeModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Form Content -->
                <form action="{{ route('admin.resellers.upgrade-package', $user) }}" method="POST" class="flex-1 overflow-y-auto p-6">
                    @csrf
                    <div class="space-y-6">
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            Current plan: <span class="font-semibold text-slate-900 dark:text-white">{{ $user->resellerPackage?->name ?? 'None' }}</span>
                        </p>

                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-4">Select New Plan</label>
                            <div class="space-y-3">
                                @foreach ($packages as $package)
                                    @if ($package->id !== ($user->reseller_package_id ?? null))
                                        <label class="flex items-start gap-3 p-4 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-950/20 cursor-pointer transition">
                                            <input type="radio" name="reseller_package_id" value="{{ $package->id }}" required class="mt-1 w-4 h-4 border border-slate-300 rounded bg-white dark:bg-slate-800 focus:ring-2 focus:ring-blue-500 accent-blue-600">
                                            <div class="flex-1">
                                                <p class="font-semibold text-slate-900 dark:text-white">{{ $package->name }}</p>
                                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $package->description }}</p>
                                                <div class="flex items-center gap-4 mt-2 text-sm">
                                                    <span class="font-medium text-slate-900 dark:text-white">
                                                        Ksh {{ number_format($package->price, 0) }}
                                                    </span>
                                                    <span class="text-slate-600 dark:text-slate-400">
                                                        {{ ucfirst($package->billing_cycle) }}
                                                    </span>
                                                    <span class="text-slate-600 dark:text-slate-400">
                                                        • {{ $package->max_users }} customers max
                                                    </span>
                                                    <span class="text-slate-600 dark:text-slate-400">
                                                        • {{ $package->storage_space }} service slots
                                                    </span>
                                                </div>
                                            </div>
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- Sticky Footer -->
                    <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 mt-6 pt-4 flex items-center justify-end gap-3">
                        <button type="button" @click="upgradeModal = false" class="px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Upgrade Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Billing Dates Modal -->
        <div x-show="editBillingModal" x-transition fixed inset-0 bg-black/50 z-50 flex items-end @click.self="editBillingModal = false">
            <div class="bg-white dark:bg-slate-900 w-full max-w-2xl mx-auto rounded-t-2xl shadow-2xl overflow-y-auto max-h-[90vh] flex flex-col">
                <!-- Sticky Header -->
                <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Edit Billing Dates</h2>
                    <button @click="editBillingModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Form Content -->
                <form action="{{ route('admin.resellers.update-billing', $user) }}" method="POST" class="flex-1 overflow-y-auto p-6">
                    @csrf
                    <div class="space-y-6">
                        <!-- Next Invoice Date -->
                        <div>
                            <label for="next_invoice_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Invoice Date</label>
                            <input type="date" id="next_invoice_date" name="next_invoice_date"
                                   value="{{ $user->next_invoice_date?->format('Y-m-d') }}"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   required>
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">
                                The date when the next invoice should be generated (suspension date will be automatically set to 10 days after)
                            </p>
                        </div>

                        <!-- Suspension Date (Read-only Display) -->
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Suspension Date (Auto-calculated)</label>
                            <div class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white">
                                <p id="suspension-date-display">
                                    @if ($user->package_suspend_date)
                                        {{ $user->package_suspend_date->format('M d, Y') }}
                                    @else
                                        —
                                    @endif
                                </p>
                            </div>
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">
                                Will be set to: <span id="suspension-date-preview">{{ $user->package_suspend_date?->format('M d, Y') ?? '—' }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Sticky Footer -->
                    <div class="sticky bottom-0 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 mt-6 pt-4 flex items-center justify-end gap-3">
                        <button type="button" @click="editBillingModal = false" class="px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Save Changes
                        </button>
                    </div>
                </form>

                <!-- JavaScript to update suspension date preview -->
                <script>
                    document.getElementById('next_invoice_date').addEventListener('change', function(e) {
                        if (e.target.value) {
                            const date = new Date(e.target.value);
                            const suspensionDate = new Date(date);
                            suspensionDate.setDate(suspensionDate.getDate() + 10);

                            const options = { year: 'numeric', month: 'short', day: 'numeric' };
                            const formatted = suspensionDate.toLocaleDateString('en-US', options);
                            document.getElementById('suspension-date-preview').textContent = formatted;
                        }
                    });
                </script>
            </div>
        </div>
    </div>
</div>
@endsection
