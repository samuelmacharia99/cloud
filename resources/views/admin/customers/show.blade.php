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
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.customers.edit', $customer) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Customer
                </a>
                <button @click="createInvoiceModal = true" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
                    Create Invoice
                </button>
                <form action="{{ route('admin.customers.destroy', $customer) }}" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this customer? This action cannot be undone.');">
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
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $customer->country ?: '-' }}</p>
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
        <!-- Action Buttons -->
        <div class="flex gap-3">
            <button @click="addServiceModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
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
                                                    &middot; <span class="font-mono">{{ $service->service_meta['domain'] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->product->name ?? '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ucfirst(str_replace('-', ' ', $service->billing_cycle)) }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($service->status === 'active') bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                            @elseif($service->status === 'suspended') bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                            @elseif($service->status === 'terminated') bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                            @else bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400 @endif">
                                            {{ ucfirst($service->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->commenced_at ? $service->commenced_at->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $service->next_due_date ? $service->next_due_date->format('M d, Y') : '-' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View</a>
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
                                    <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">{{ $domain->name }}{{ $domain->extension }}</td>
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
                                        <span class="text-slate-500 dark:text-slate-400 text-sm font-medium">-</span>
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
                        @foreach ($customer->invoices as $invoice)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#{{ str_pad($invoice->id, 5, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ $invoice->due_at ? $invoice->due_at->format('M d, Y') : '-' }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">KSH {{ number_format($invoice->total, 2) }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                        Draft
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
                    <p class="text-slate-600 dark:text-slate-400">No invoices found.</p>
                </div>
            @endif
        </div>
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
    <div x-show="addServiceModal" x-transition class="fixed inset-0 bg-black/50 z-50 flex items-end" @click.self="addServiceModal = false">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-t-2xl shadow-2xl overflow-y-auto max-h-screen">
        <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 z-10">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add Service</h2>
            <button @click="addServiceModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.customers.add-service', $customer) }}" class="p-6 space-y-6" @submit="onAddServiceSubmit($event)">
            @csrf

            <!-- Product Selection -->
            <div>
                <label for="product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product <span class="text-red-500">*</span></label>
                <select id="product_id" name="product_id" x-model="selectedProduct" @change="onProductChange()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="">Select a product</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }} &mdash; {{ \App\Models\Product::typeLabel($product->type) }}</option>
                    @endforeach
                </select>

                <!-- Shared hosting package summary -->
                <template x-if="isSharedHosting() && currentProduct()?.direct_admin_package">
                    <div class="mt-3 px-4 py-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-sm">
                        <p class="font-semibold text-blue-700 dark:text-blue-300">
                            DirectAdmin package: <span x-text="currentProduct()?.direct_admin_package?.name"></span>
                        </p>
                        <p class="text-blue-700 dark:text-blue-400 mt-1 text-xs">
                            <span x-text="currentProduct()?.direct_admin_package?.disk_quota + ' GB disk &middot; ' + currentProduct()?.direct_admin_package?.bandwidth_quota + ' GB bandwidth'"></span>
                            <template x-if="currentProduct()?.direct_admin_package?.node">
                                <span> &middot; Server: <span x-text="currentProduct()?.direct_admin_package?.node?.name"></span></span>
                            </template>
                        </p>
                    </div>
                </template>

                <template x-if="isSharedHosting() && !currentProduct()?.direct_admin_package">
                    <div class="mt-3 px-4 py-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-sm text-amber-700 dark:text-amber-300">
                        This shared-hosting product has no DirectAdmin package linked. Edit the product to attach one before continuing.
                    </div>
                </template>
            </div>

            <!-- Service Name -->
            <div>
                <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Service Name <span class="text-red-500">*</span></label>
                <input type="text" id="name" name="name" x-model="productName" placeholder="e.g., My Web Hosting" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>

            <!-- Billing Cycle -->
            <div>
                <label for="billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle <span class="text-red-500">*</span></label>
                <select id="billing_cycle" name="billing_cycle" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="semi-annual">Semi-Annual</option>
                    <option value="annual">Annual</option>
                </select>
            </div>

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

    <!-- Add Domain Modal -->
    <div x-show="addDomainModal" x-transition class="fixed inset-0 bg-black/50 z-50 flex items-end" @click.self="addDomainModal = false">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-t-2xl shadow-2xl overflow-y-auto max-h-screen">
        <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add Domain</h2>
            <button @click="addDomainModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.customers.add-domain', $customer) }}" class="p-6 space-y-6">
            @csrf

            <!-- Domain Name -->
            <div>
                <label for="domain_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Domain Name <span class="text-red-500">*</span></label>
                <input type="text" id="domain_name" name="domain_name" placeholder="e.g., example.co.ke" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>

            <!-- Status -->
            <div>
                <label for="domain_status" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Status <span class="text-red-500">*</span></label>
                <select id="domain_status" name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="expired">Expired</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <!-- Dates -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="registered_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Registration Date</label>
                    <input type="date" id="registered_at" name="registered_at" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                </div>

                <div>
                    <label for="expires_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Expiration Date <span class="text-red-500">*</span></label>
                    <input type="date" id="expires_at" name="expires_at" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                </div>
            </div>

            <!-- Next Invoice Date -->
            <div>
                <label for="next_due_date_domain" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Invoice Date (Optional)</label>
                <input type="date" id="next_due_date_domain" name="next_due_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">Leave blank if you don't want to generate an invoice</p>
            </div>

            <!-- Nameservers -->
            <div class="space-y-3">
                <div>
                    <label for="nameserver_1" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 1</label>
                    <input type="text" id="nameserver_1" name="nameserver_1" placeholder="e.g., ns1.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                </div>

                <div>
                    <label for="nameserver_2" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Nameserver 2</label>
                    <input type="text" id="nameserver_2" name="nameserver_2" placeholder="e.g., ns2.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                </div>
            </div>

            <!-- Auto Renew -->
            <div class="flex items-center gap-3">
                <input type="checkbox" id="auto_renew" name="auto_renew" value="1" class="w-4 h-4 border-slate-300 rounded">
                <label for="auto_renew" class="text-sm text-slate-900 dark:text-white">Auto-renew domain</label>
            </div>

            <!-- Notes -->
            <div>
                <label for="domain_notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                <textarea id="domain_notes" name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white"></textarea>
            </div>

            <!-- Submit Button -->
            <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                <button type="button" @click="addDomainModal = false" class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                    Add Domain
                </button>
            </div>
        </form>
        </div>
    </div>

    <!-- Create Invoice Modal -->
    <div x-show="createInvoiceModal" x-transition class="fixed inset-0 bg-black/50 z-50 flex items-end" @click.self="createInvoiceModal = false">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-t-2xl shadow-2xl overflow-y-auto max-h-screen">
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Create Invoice for {{ $customer->name }}</h2>
                <button @click="createInvoiceModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.customers.create-invoice', $customer) }}" class="p-6 space-y-6">
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
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tax Rate (%)</label>
                    <input type="number" name="tax_rate" x-model="taxRate" min="0" max="100" step="0.01" value="0" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                </div>

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
                            <input
                                type="number"
                                :name="`items[${index}][unit_price]`"
                                x-model="item.unit_price"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                required
                                class="col-span-3 px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm"
                            />
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

<script>
function initCustomerData() {
    return {
        tab: 'overview',
        addServiceModal: false,
        addDomainModal: false,
        createInvoiceModal: false,
        productName: '',
        password_visible: false,
        selectedProduct: '',
        products: @json($productsForJs),

        // DirectAdmin (shared hosting) form state
        daUsername: '',
        daPassword: '',
        daDomain: '',
        generatingPassword: false,
        generatingUsername: false,
        passwordCopied: false,

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

        onProductChange() {
            const product = this.currentProduct();
            this.productName = product?.name || '';

            // Reset DA fields when switching products so stale values don't leak
            // between selections.
            if (this.isSharedHosting()) {
                this.daUsername = '';
                this.daPassword = '';
                this.daDomain = '';
                this.suggestUsername();
            } else {
                this.daUsername = '';
                this.daPassword = '';
                this.daDomain = '';
            }
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

        onAddServiceSubmit(e) {
            // Extra client-side guard: shared hosting needs all DA fields.
            if (this.isSharedHosting()) {
                if (!this.currentProduct()?.direct_admin_package) {
                    e.preventDefault();
                    alert('This shared-hosting product has no DirectAdmin package linked. Edit the product to attach one before continuing.');
                    return false;
                }
                if (!this.daUsername || !this.daPassword || !this.daDomain) {
                    e.preventDefault();
                    alert('DirectAdmin username, password, and primary domain are required for shared hosting.');
                    return false;
                }
            }
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
            return 'Ksh ' + parseFloat(v||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
    }
}
</script>
@endsection
