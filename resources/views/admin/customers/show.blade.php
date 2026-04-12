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
                <button class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 font-medium rounded-lg transition text-sm">
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
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Next Due</th>
                                <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($customer->services as $service)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">{{ $service->name }}</td>
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
        <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Add Service</h2>
            <button @click="addServiceModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.customers.add-service', $customer) }}" class="p-6 space-y-6">
            @csrf

            <!-- Product Selection -->
            <div>
                <label for="product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product <span class="text-red-500">*</span></label>
                <select id="product_id" name="product_id" x-model="selectedProduct" @change="productName = products.find(p => p.id == selectedProduct)?.name || ''" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                    <option value="">Select a product</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </select>
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

            <!-- Credentials Section -->
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Service Credentials (Optional)</h3>
                <div class="space-y-4">
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Username</label>
                        <input type="text" id="username" name="username" placeholder="e.g., admin" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password</label>
                        <div class="relative">
                            <input :type="password_visible ? 'text' : 'password'" id="password" name="password" placeholder="e.g., Secure@123" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white pr-10">
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

            <!-- Billing Dates -->
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6 space-y-4">
                <div>
                    <label for="next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date <span class="text-red-500">*</span></label>
                    <input type="date" id="next_due_date" name="next_due_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
                </div>

                <div>
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
                <label for="generate_invoice" class="text-sm text-slate-900 dark:text-white">Generate invoice for this service</label>
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
</div>

<script>
function initCustomerData() {
    return {
        tab: 'overview',
        addServiceModal: false,
        addDomainModal: false,
        productName: '',
        password_visible: false,
        selectedProduct: '',
        products: @json($productsForJs)
    }
}
</script>
@endsection
